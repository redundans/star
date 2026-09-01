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

class PostToBluesky
{
    protected $client;
    protected $config;
    protected $logger;
    protected $url;

    public function __construct(Config $config, LoggerInterface $logger, UrlGenerator $url)
    {
        $this->client = new Client(['base_uri' => 'https://bsky.social']);
        $this->config = $config;
        $this->logger = $logger;
        $this->url = $url;
    }

    public function handle(Saving $event)
    {
        $post = $event->post;

        if (!($post instanceof CommentPost) || !$post->is_starred) {
            return;
        }

        // Felsäkert sätt att läsa från config.php
        $flarumConfig = include app()->basePath() . '/config.php';
        $blueskyConfig = $flarumConfig['bluesky'] ?? [];

        $handle = $blueskyConfig['handle'] ?? null;
        $appPassword = $blueskyConfig['app_password'] ?? null;

        if (!$handle || !$appPassword) {
            $this->logger->error('Bluesky extension: Saknar handle eller app_password i config.php');
            return;
        }

        try {
            // 1. Autentisera och hämta JWT-token
            $authResponse = $this->client->post('/xrpc/com.atproto.server.createSession', [
                'json' => [
                    'identifier' => $handle,
                    'password' => $appPassword,
                ]
            ]);

            $authData = json_decode($authResponse->getBody()->getContents(), true);
            $jwt = $authData['accessJwt'];
            $did = $authData['did'];

            // 2. Extrahera bild-URL och alt-text från [upl-image-preview ...]
            $rawContent = $post->content ?? '';
            $embedData = null;
            $uploadedImages = [];

            // Hitta ALLA förekomster av [upl-image-preview ...] i inlägget
            if (preg_match_all('/\[upl-image-preview[^\]]*url=([^\s\]]+)[^\]]*alt=([^\s\]]+)?[^\]]*\]/', $rawContent, $matches, PREG_SET_ORDER)) {

                foreach ($matches as $match) {
                    // Bluesky tillåter max 4 bilder per inlägg
                    if (count($uploadedImages) >= 4) {
                        break;
                    }

                    $imageUrl = $match[1];
                    $imageAlt = isset($match[2]) ? urldecode($match[2]) : 'Bifogad bild';

                    // Extrahera den relativa sökvägen till assets
                    $pathParts = explode('/assets/', $imageUrl);
                    $relativeAssetPath = isset($pathParts[1]) ? $pathParts[1] : null;

                    if ($relativeAssetPath) {
                        $localFilePath = public_path('assets/' . $relativeAssetPath);

                        if (file_exists($localFilePath)) {
                            $imageContent = file_get_contents($localFilePath);

                            $finfo = new \finfo(FILEINFO_MIME_TYPE);
                            $mimeType = $finfo->file($localFilePath);

                            // Ladda upp bilden som en Blob till Bluesky
                            $uploadResponse = $this->client->post('/xrpc/com.atproto.repo.uploadBlob', [
                                'headers' => [
                                    'Authorization' => 'Bearer ' . $jwt,
                                    'Content-Type' => $mimeType,
                                ],
                                'body' => $imageContent
                            ]);

                            $uploadData = json_decode($uploadResponse->getBody()->getContents(), true);

                            if (isset($uploadData['blob'])) {
                                $uploadedImages[] = [
                                    'image' => $uploadData['blob'],
                                    'alt' => $imageAlt
                                ];
                            }
                        } else {
                            $this->logger->error('Bluesky extension: Filen hittades inte på disken: ' . $localFilePath);
                        }
                    }
                }

                // Om vi lyckades ladda upp minst en bild, bygg embed-strukturen
                if (!empty($uploadedImages)) {
                    $embedData = [
                        '$type' => 'app.bsky.embed.images',
                        'images' => $uploadedImages
                    ];
                }
            }

            // 3. Formatera texten och rensa bort ALLA bildtaggar från Bluesky-texten
            $cleanContent = preg_replace('/\[upl-image-preview[^\]]*\]/', '', $rawContent);
            $text = strip_tags((string) $cleanContent);
            $text = trim($text);

            if (empty($text)) {
                $text = "Nytt stjärnmärkt inlägg!";
            }

            // Generera den direkta URL:en till kommentaren i Flarum
            $postUrl = $this->url->to('forum')->route('discussion', [
                'id' => $post->discussion_id,
                'near' => $post->number
            ]);

            $lineBreak = "\n\n";
            $linkText = " 🔗 Läs på forumet";

            $maxTextLength = 280 - mb_strlen($linkText);
            if (mb_strlen($text) > $maxTextLength) {
                $text = mb_substr($text, 0, $maxTextLength - 3) . '...';
            }

            $text .= $lineBreak;

            $startByte = strlen($text);
            $text .= $linkText;
            $endByte = strlen($text);

            // Skapa facets-strukturen som Bluesky kräver för klickbara länkar
            $facets = [
                [
                    'index' => [
                        'byteStart' => $startByte,
                        'byteEnd' => $endByte
                    ],
                    'features' => [
                        [
                            '$type' => 'app.bsky.richtext.facet#link',
                            'uri' => $postUrl
                        ]
                    ]
                ]
            ];

            // 4. Bygg ihop payloaden till Bluesky
            $recordPayload = [
                'repo' => $did,
                'collection' => 'app.bsky.feed.post',
                'record' => [
                    'text' => $text,
                    'facets' => $facets,
                    'createdAt' => date('c'),
                    '$type' => 'app.bsky.feed.post'
                ]
            ];

            // Om vi lyckades ladda upp en bild, lägg till den i inlägget
            if ($embedData) {
                $recordPayload['record']['embed'] = $embedData;
            }

            // Skapa inlägget på Bluesky
            $this->client->post('/xrpc/com.atproto.repo.createRecord', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $jwt,
                ],
                'json' => $recordPayload
            ]);

        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'Ingen body';
            $this->logger->error('Bluesky API Fel: ' . $responseBody);
        } catch (\Exception $e) {
            $this->logger->error('Bluesky Allmänt Fel: ' . $e->getMessage());
        }
    }
}
