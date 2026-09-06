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
            $authResponse = $client->post('/xrpc/com.atproto.server.createSession', [
                'json' => [
                    'identifier' => $config['handle'],
                    'password' => $config['app_password'],
                ]
            ]);
            $authData = json_decode($authResponse->getBody()->getContents(), true);
            $jwt = $authData['accessJwt'];
            $did = $authData['did'];

            $embedData = null;
            $uploadedImages = [];
            $cleanContent = $post->content ?? '';

            if (preg_match_all('/\!\[(.*?)\]\((.*?)\)/', $post->content ?? '', $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $cleanContent = str_replace($match[0], '', $cleanContent);

                    if (count($uploadedImages) >= 4) break;

                    $altText = !empty($match[1]) ? $match[1] : 'Bifogad bild';
                    $imageUrl = $match[2];
                    $imageData = null;
                    $mimeType = null;

                    if (str_contains($imageUrl, '/assets/')) {
                        $pathParts = explode('/assets/', $imageUrl);
                        $localPath = public_path('assets/' . $pathParts[1]);

                        if (file_exists($localPath)) {
                            $imageData = file_get_contents($localPath);
                            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);
                        }
                    } elseif (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                        $externalData = @file_get_contents($imageUrl);
                        if ($externalData !== false) {
                            $imageData = $externalData;
                            $ext = pathinfo($imageUrl, PATHINFO_EXTENSION);
                            $mimeType = match(strtolower($ext)) {
                                'jpg', 'jpeg' => 'image/jpeg',
                                'png' => 'image/png',
                                'gif' => 'image/gif',
                                'webp' => 'image/webp',
                                default => 'image/jpeg',
                            };
                        }
                    }

                    if ($imageData && $mimeType) {
                        try {
                            $uploadResponse = $client->post('/xrpc/com.atproto.repo.uploadBlob', [
                                'headers' => [
                                    'Authorization' => 'Bearer ' . $jwt,
                                    'Content-Type' => $mimeType
                                ],
                                'body' => $imageData
                            ]);

                            $uploadData = json_decode($uploadResponse->getBody()->getContents(), true);
                            if (isset($uploadData['blob'])) {
                                $uploadedImages[] = [
                                    'image' => $uploadData['blob'],
                                    'alt' => $altText
                                ];
                            }
                        } catch (\Exception $e) {
                            logger()->error("Kunde inte ladda upp bild till Bluesky: " . $e->getMessage());
                        }
                    }
                }

                if (!empty($uploadedImages)) {
                    $embedData = ['$type' => 'app.bsky.embed.images', 'images' => $uploadedImages];
                }
            }

            $text = strip_tags((string) $cleanContent);
            $text = trim($text);

            if (empty($text)) {
                $text = "Nytt inlägg på Noden Forum!";
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
            $cleanContent = $post->content ?? '';

            if (preg_match_all('/\!\[(.*?)\]\((.*?)\)/', $post->content ?? '', $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $cleanContent = str_replace($match[0], '', $cleanContent);

                    if (count($mediaIds) >= 4) break;

                    $altText = !empty($match[1]) ? urldecode($match[1]) : 'Bifogad bild';
                    $imageUrl = $match[2];
                    $fileContents = null;
                    $fileName = 'image.jpg'; // Standardnamn om det behövs

                    if (str_contains($imageUrl, '/assets/')) {
                        // Lokal filhantering
                        $pathParts = explode('/assets/', $imageUrl);
                        if (isset($pathParts[1]) && file_exists($localPath = public_path('assets/' . $pathParts[1]))) {
                            $fileContents = fopen($localPath, 'r');
                            $fileName = basename($localPath);
                        }
                    } elseif (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                        // Extern filhantering (laddar ner bilden)
                        $options = [
                            "http" => [
                                "method" => "GET",
                                "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n"
                            ]
                        ];
                        $context = stream_context_create($options);
                        $externalData = @file_get_contents($imageUrl, false, $context);
                        if ($externalData !== false) {
                            $fileContents = $externalData;
                            $fileName = basename(parse_url($imageUrl, PHP_URL_PATH)) ?: 'image.jpg';
                        }
                    }

                    if ($fileContents !== null) {
                        try {
                            $uploadResponse = $client->post('/api/v1/media', [
                                'multipart' => [
                                    [
                                        'name'     => 'file',
                                        'contents' => $fileContents,
                                        'filename' => $fileName
                                    ],
                                    [
                                        'name'     => 'description',
                                        'contents' => $altText
                                    ]
                                ]
                            ]);

                            $uploadData = json_decode($uploadResponse->getBody()->getContents(), true);
                            if (isset($uploadData['id'])) {
                                $mediaIds[] = $uploadData['id'];
                            }
                        } catch (\Exception $e) {
                            logger()->error("Kunde inte ladda upp bild till Mastodon: " . $e->getMessage());
                        }
                    }
                }
            }

            $cleanContent = trim($cleanContent);
            $text = strip_tags((string) $cleanContent);

            if (empty($text)) {
                $text = "Nytt inlägg på Noden Forum!";
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
