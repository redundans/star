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
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

class PublishStarredPost
{
    protected $config;
    protected $logger;
    protected $url;
    protected $filesystem;
    protected $assetsDisk;

    public function __construct(Config $config, LoggerInterface $logger, UrlGenerator $url)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->url = $url;
        $this->filesystem = resolve(FilesystemFactory::class);
        $this->assetsDisk = $this->filesystem->disk('flarum-assets');
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

    public function fetchImageObject(String $imageUrl): Object {
        $imageObject = (Object) [
            'fileName' => null,
            'mimeType' => null,
            'imageData' => null,
            'altText' => null,
        ];

        $imageObject->altText = 'Bifogad bild';

        if (str_contains($imageUrl, '/assets/')) {
            $pathParts = explode('/assets/', $imageUrl);
            $localPath = public_path('assets/' . $pathParts[1]);

            if (file_exists($localPath)) {
                $imageObject->imageData = file_get_contents($localPath);
                $imageObject->mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);
                $imageObject->fileName = basename($localPath);
            }
        } elseif (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
            $externalData = @file_get_contents(
                $imageUrl,
                false,
                stream_context_create(
                    [
                        "http" => [
                            "method" => "GET",
                            "header" => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\r\n"
                        ]
                    ]
                )
            );
            if ($externalData !== false) {
                $imageObject->fileName = basename(parse_url($imageUrl, PHP_URL_PATH)) ?: 'image.jpg';
                $imageObject->imageData = $externalData;
                $ext = pathinfo($imageUrl, PATHINFO_EXTENSION);
                $imageObject->mimeType = match(strtolower($ext)) {
                    'jpg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
            }
        }
        return $imageObject;
    }

    protected function truncate(String $text, Int $maxTextLength): String
    {
        if (mb_strlen($text) > $maxTextLength) {
            $text = mb_substr($text, 0, $maxTextLength - 3) . '...';
        }
        return $text;
    }

    protected function uploadToMastodon(Object $imageObject, Client $client): String
    {
        $uploadedId = null;
        try {
            $uploadResponse = $client->post('/api/v1/media', [
                'multipart' => [
                    [
                        'name'     => 'file',
                        'contents' => $imageObject->imageData,
                        'filename' => $imageObject->fileName
                    ],
                    [
                        'name'     => 'description',
                        'contents' => $imageObject->altText
                    ]
                ]
            ]);

            $uploadData = json_decode($uploadResponse->getBody()->getContents(), true);
            if (isset($uploadData['id'])) {
                $uploadedId = $uploadData['id'];
            }
        } catch (\Exception $e) {
            logger()->error("Kunde inte ladda upp bild till Mastodon: " . $e->getMessage());
        }
        return $uploadedId;
    }

    protected function uploadToBluesky(Object $imageObject, Client $client, String $jwt): Array
    {
        $uploadedImage = [];
        try {
            $uploadResponse = $client->post('/xrpc/com.atproto.repo.uploadBlob', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $jwt,
                    'Content-Type' => $imageObject->mimeType
                ],
                'body' => $imageObject->imageData
            ]);
            $uploadData = json_decode($uploadResponse->getBody()->getContents(), true);
            if (isset($uploadData['blob'])) {
                $uploadedImage = [
                    'image' => $uploadData['blob'],
                    'alt' => $imageObject->altText
                ];
            }
        } catch (\Exception $e) {
            logger()->error("Kunde inte ladda upp bild till Bluesky: " . $e->getMessage());
        }
        return $uploadedImage;
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

            if (!empty($post->discussion->linkposter_thumbnail) && !empty($post->discussion->linkposter_description)) {
                $imageUrl = $this->assetsDisk->url("linkposter/{$post->discussion->linkposter_thumbnail}");
                $imageObject = $this->fetchImageObject( $imageUrl );
                if ($imageObject) {
                    $uploadedImages[] = $this->uploadToBluesky($imageObject, $client, $jwt);
                }
                $cleanContent = "{$post->discussion->title}\n\n{$post->discussion->linkposter_description}";
            }

            if (preg_match_all('/(?:!\[(?P<alt_md>.*?)\]\((?P<url_md>.*?)\)|\[upl-image-preview\b[\s\S]*?\burl=(?P<url_upl>https?:\/\/\S+)[\s\S]*?\balt=(?P<alt_upl>[^\s\]]+)[\s\S]*?\])/i', $cleanContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $cleanContent = str_replace($match[0], '', $cleanContent);

                    if (count($uploadedImages) >= 4) break;

                    $imageUrl = !empty($match[2]) ? $match[2] : ($match[3] ?? null);
                    $imageObject = $this->fetchImageObject( $imageUrl );

                    if ($imageObject) {
                        $uploadedImages[] = $this->uploadToBluesky($imageObject, $client, $jwt);
                    }
                }
            }

            if (!empty($uploadedImages)) {
                $embedData = ['$type' => 'app.bsky.embed.images', 'images' => $uploadedImages];
            }

            $text = strip_tags((string) $cleanContent);
            $text = trim($text);

            if (empty($text)) {
                $text = "Nytt inlägg på Noden Forum!";
            }

            $lineBreak = "\n\n";
            $linkText = "🔗 Läs på forumet";
            $maxTextLength = 280 - mb_strlen($lineBreak . $linkText);
            $text = $this->truncate($text, $maxTextLength);

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

            if (!empty($post->discussion->linkposter_thumbnail) && !empty($post->discussion->linkposter_description)) {
                $imageUrl = $this->assetsDisk->url("linkposter/{$post->discussion->linkposter_thumbnail}");
                $imageObject = $this->fetchImageObject( $imageUrl );
                if ($imageObject) {
                    $mediaIds[] = $this->uploadToMastodon($imageObject, $client);
                }
                $cleanContent = "{$post->discussion->title}\n\n{$post->discussion->linkposter_description}";
            }

            if (preg_match_all('/(?:!\[(?P<alt_md>.*?)\]\((?P<url_md>.*?)\)|\[upl-image-preview\b[\s\S]*?\burl=(?P<url_upl>https?:\/\/\S+)[\s\S]*?\balt=(?P<alt_upl>[^\s\]]+)[\s\S]*?\])/i', $cleanContent, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $cleanContent = str_replace($match[0], '', $cleanContent);

                    if (count($mediaIds) >= 4) break;

                    $imageUrl = !empty($match[2]) ? $match[2] : ($match[3] ?? null);
                    $imageObject = $this->fetchImageObject( $imageUrl );

                    if ($imageObject->imageData !== null) {
                        $mediaIds[] = $this->uploadToMastodon($imageObject, $client);
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
            $text = $this->truncate($text, $maxTextLength);

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
