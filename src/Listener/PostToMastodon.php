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

class PostToMastodon
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

		if (!($post instanceof CommentPost) || !$post->is_starred) {
			return;
		}

		$flarumConfig = include app()->basePath() . '/config.php';
		$mastodonConfig = $flarumConfig['mastodon'] ?? [];
		
		$instanceUrl = rtrim($mastodonConfig['instance_url'] ?? '', '/');
		$accessToken = $mastodonConfig['access_token'] ?? null;

		if (!$instanceUrl || !$accessToken) {
			$this->logger->error('Mastodon extension: Saknar instance_url eller access_token i config.php');
			return;
		}

		try {
			$client = new Client([
				'base_uri' => $instanceUrl,
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
				]
			]);

			$rawContent = $post->content ?? '';
			$mediaIds = [];

			if (preg_match_all('/\[upl-image-preview[^\]]*url=([^\s\]]+)[^\]]*alt=([^\s\]]+)?[^\]]*\]/', $rawContent, $matches, PREG_SET_ORDER)) {
				foreach ($matches as $match) {
					if (count($mediaIds) >= 4) {
						break;
					}

					$imageUrl = $match[1];
					$imageAlt = isset($match[2]) ? urldecode($match[2]) : 'Bifogad bild';

					$pathParts = explode('/assets/', $imageUrl);
					$relativeAssetPath = isset($pathParts[1]) ? $pathParts[1] : null;

					if ($relativeAssetPath) {
						$localFilePath = public_path('assets/' . $relativeAssetPath);

						if (file_exists($localFilePath)) {
							$uploadResponse = $client->post('/api/v1/media', [
								'multipart' => [
									[
										'name'     => 'file',
										'contents' => fopen($localFilePath, 'r'),
									],
									[
										'name'     => 'description',
										'contents' => $imageAlt,
									]
								]
							]);
							$uploadData = json_decode($uploadResponse->getBody()->getContents(), true);
							if (isset($uploadData['id'])) {
								$mediaIds[] = $uploadData['id'];
							}
						}
					}
				}
			}

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

		} catch (\GuzzleHttp\Exception\BadResponseException $e) {
			$responseBody = $e->getResponse() ? $e->getResponse()->getBody()->getContents() : 'Ingen body';
			$this->logger->error('Mastodon API Fel: ' . $responseBody);
		} catch (\Exception $e) {
			$this->logger->error('Mastodon Allmänt Fel: ' . $e->getMessage());
		}
	}
}
