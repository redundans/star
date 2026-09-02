<?php

/*
 * This file is part of redundans/star-bluesky.
 *
 * Copyright (c) 2026 redundans.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace redundans\Star\Listener;

use Flarum\Foundation\Config;
use Flarum\Http\UrlGenerator;
use Flarum\Post\Event\Saving;
use Flarum\Post\CommentPost;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

class PublishStarredPost
{
    protected $config;
    protected $logger;
    protected $url;

    public function __construct(Config $config, LoggerInterface $logger, UrlGenerator $url)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->url = $url;
    }

    public function handle(Saving $event)
    {
        $post = $event->post;

        if (!($post instanceof CommentPost) || !$post->is_starred || $post->is_synced_to_social) {
            return;
        }

        if (!$post->exists) {
            return;
        }

        $flarumConfig = include app()->basePath() . '/config.php';
        $blueskyConfig = $flarumConfig['bluesky'] ?? [];
        $mastodonConfig = $flarumConfig['mastodon'] ?? [];

        $postUrl = $this->url->to('forum')->route('discussion', [
            'id' => $post->discussion_id,
            'near' => $post->number
        ]);

        $bskySuccess = $this->publishToBluesky($post, $postUrl, $blueskyConfig);
        $mstdnSuccess = $this->publishToMastodon($post, $postUrl, $mastodonConfig);

        if ($bskySuccess || $mstdnSuccess) {
            $post->is_synced_to_social = true;
        }
    }

    protected function publishToBluesky(CommentPost $post, string $postUrl, array $config): bool
    {
        if (empty($config['handle']) || empty($config['app_password'])) {
            return false;
        }

        try {
            $client = new Client(['base_uri' => 'https://bsky.social']);

            // 1. Autentisering
            $authResponse = $client->post('/xrpc/com.atproto.server.createSession', [
                'json' => [
                    'identifier' => $config['handle'],
                    'password' => $config['app_password'],
                ]
            ]);
            $authData = json_decode($authResponse->getBody()->getContents(), true);
            $jwt = $authData['accessJwt'];
            $did = $authData['did'];

            // 2. Bilder (Max 4)
            $embedData = null;
            $uploadedImages = [];
            if (preg_match_all('/\[upl-image-preview[^\]]*url=([^\s\]]+)[^\]]*alt=([^\s\]]+)?[^\]]*\]/', $post->content ?? '', $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (count($uploadedImages) >= 4) break;
                    $pathParts = explode('/assets/', $match[1]);
                    if (isset($pathParts[1]) && file_exists($localPath = public_path('assets/' . $pathParts[1]))) {
                        $uploadResponse = $client->post('/xrpc/com.atproto.repo.uploadBlob', [
                            'headers' => [
                                'Authorization' => 'Bearer ' . $jwt,
                                'Content-Type' => (new \finfo(FILEINFO_MIME_TYPE))->file($localPath)
                            ],
                            'body' => file_get_contents($localPath)
                        ]);
                        $uploadData = json_decode($uploadResponse->getBody()->getContents(), true);
                        if (isset($uploadData['blob'])) {
                            $uploadedImages[] = [
                                'image' => $uploadData['blob'],
                                'alt' => isset($match[2]) ? urldecode($match[2]) : 'Bifogad bild'
                            ];
                        }
                    }
                }
                if (!empty($uploadedImages)) {
                    $embedData = ['$type' => 'app.bsky.embed.images', 'images' => $uploadedImages];
                }
            }

            $rawContent = $post->content ?? '';

            $cleanContent = preg_replace('/\[upl-image-preview[^\]]*\]/', '', $rawContent);
            $text = strip_tags((string) $cleanContent);
            $text = trim($text);

            if (empty($text)) {
                $text = "Nytt stjärnmärkt inlägg!";
            }

            $lineBreak = "\n\n";
            $linkText = "🔗 Läs på forumet";
            $maxTextLength = 280 - mb_strlen($lineBreak . $linkText);
            if (mb_strlen($text) > $maxTextLength) {
                $text = mb_substr($text, 0, $maxTextLength - 3) . '...';
            }

            $text .= $lineBreak;
            $startByte = strlen($text);
            $text .= $linkText;
            $endByte = strlen($text);

            $recordPayload = [
                'repo' => $did,
                'collection' => 'app.bsky.feed.post',
                'record' => [
                    'text' => $text,
                    'facets' => [[
                        'index' => ['byteStart' => $startByte, 'byteEnd' => $endByte],
                        'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $postUrl]]
                    ]],
                    'createdAt' => date('c'),
                    '$type' => 'app.bsky.feed.post'
                ]
            ];
            if ($embedData) $recordPayload['record']['embed'] = $embedData;

            $client->post('/xrpc/com.atproto.repo.uploadBlob' === null ? '/xrpc/com.atproto.repo.createRecord' : '/xrpc/com.atproto.repo.createRecord', [
                'headers' => ['Authorization' => 'Bearer ' . $jwt],
                'json' => $recordPayload
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Social Sync: Fel vid Bluesky-publicering: ' . $e->getMessage());
            return false;
        }
    }

    protected function publishToMastodon(CommentPost $post, string $postUrl, array $config): bool
    {
        if (empty($config['instance_url']) || empty($config['access_token'])) {
            return false;
        }

        try {
            $client = new Client([
                'base_uri' => rtrim($config['instance_url'], '/'),
                'headers' => ['Authorization' => 'Bearer ' . $config['access_token']]
            ]);

            $mediaIds = [];
            if (preg_match_all('/\[upl-image-preview[^\]]*url=([^\s\]]+)[^\]]*alt=([^\s\]]+)?[^\]]*\]/', $post->content ?? '', $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (count($mediaIds) >= 4) break;
                    $pathParts = explode('/assets/', $match[1]);
                    if (isset($pathParts[1]) && file_exists($localPath = public_path('assets/' . $pathParts[1]))) {
                        $uploadResponse = $client->post('/api/v1/media', [
                            'multipart' => [
                                ['name' => 'file', 'contents' => fopen($localPath, 'r')],
                                ['name' => 'description', 'contents' => isset($match[2]) ? urldecode($match[2]) : 'Bifogad bild']
                            ]
                        ]);
                        $uploadData = json_decode($uploadResponse->getBody()->getContents(), true);
                        if (isset($uploadData['id'])) {
                            $mediaIds[] = $uploadData['id'];
                        }
                    }
                }
            }

            $rawContent = $post->content ?? '';
            $cleanContent = preg_replace('/\[upl-image-preview[^\]]*\]/', '', $rawContent);
            $text = strip_tags((string) $cleanContent);
            $text = trim($text);

            if (empty($text)) {
                $text = "Nytt stjärnmärkt inlägg!";
            }

            $postUrl = $this->url->to('forum')->route('discussion', [
                'id' => $post->discussion_id,
                'near' => $post->number
            ]);

            $linkText = "\n\n🔗 Läs på forumet:\n" . $postUrl;

            $maxTextLength = 500 - strlen($linkText);
            if (mb_strlen($text) > $maxTextLength) {
                $text = mb_substr($text, 0, $maxTextLength - 3) . '...';
            }

            $statusText = $text . $linkText;

            $postPayload = [
                'status' => $statusText,
            ];

            if (!empty($mediaIds)) {
                $postPayload['media_ids'] = $mediaIds;
            }

            $client->post('/api/v1/statuses', [
                'json' => $postPayload
            ]);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Social Sync: Fel vid Mastodon-publicering: ' . $e->getMessage());
            return false;
        }
    }
}
