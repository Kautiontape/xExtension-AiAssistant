<?php

declare(strict_types=1);

/**
 * AI Assistant extension.
 *
 * Thin display layer over the `assistant` service (see ../assistant in the
 * deploy repo). The service scores entries in the background and stores the
 * results in the entry attributes this extension renders. On-demand actions
 * (summarize, detail, chat, feedback, transcript, full content) are proxied to
 * the service so all prompts and model settings live in one place.
 */
final class AiAssistantExtension extends Minz_Extension {

	/** Attribute keys written by the service that must survive entry updates. */
	private const AI_KEYS = ['ai_score', 'ai_score_reason', 'ai_summary', 'ai_summary_full', 'ai_detail',
		'yt_transcript', 'yt_is_short', 'yt_duration', 'full_content'];
	private const TOPIC_PREFIX = 'ai/';

	private static ?array $jsonInput = null;

	public string $serviceUrl = '';
	public string $publicUrl = '';
	public bool $tokenFromEnv = false;
	public bool $tokenConfigured = false;

	private static function jsonParam(string $key): mixed {
		if (self::$jsonInput === null) {
			$raw = file_get_contents('php://input');
			$decoded = $raw ? json_decode($raw, true) : null;
			self::$jsonInput = is_array($decoded) ? $decoded : [];
		}
		return self::$jsonInput[$key] ?? '';
	}

	// ── Lifecycle ────────────────────────────────────────────────────────────

	#[\Override]
	public function init(): void {
		$this->registerHook('entry_before_display', [$this, 'hookEntryBeforeDisplay']);
		$this->registerHook('entry_before_update', [$this, 'hookEntryBeforeUpdate']);
		Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
		Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
	}

	// ── Service connection ───────────────────────────────────────────────────

	private function serviceUrl(): string {
		$url = (string) ($this->getUserConfigurationValue('assistant_url') ?? '');
		if ($url === '') {
			$url = (string) (getenv('ASSISTANT_URL') ?: 'http://assistant:8000');
		}
		return rtrim($url, '/');
	}

	private function serviceToken(): string {
		$token = (string) ($this->getUserConfigurationValue('assistant_token') ?? '');
		if ($token === '') {
			$token = (string) (getenv('ASSISTANT_INTERNAL_TOKEN') ?: '');
		}
		return $token;
	}

	private function publicUrl(): string {
		$url = (string) ($this->getUserConfigurationValue('assistant_public_url') ?? '');
		if ($url === '') {
			$url = (string) (getenv('ASSISTANT_PUBLIC_URL') ?: '');
		}
		return rtrim($url, '/');
	}

	/**
	 * JSON request to the service. Returns [httpCode, decodedBody|null].
	 * @return array{0:int,1:?array}
	 */
	private function serviceJson(string $method, string $path, ?array $body = null, int $timeout = 120): array {
		$ch = curl_init($this->serviceUrl() . $path);
		$headers = ['Accept: application/json', 'X-Assistant-Token: ' . $this->serviceToken()];
		$opts = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_CUSTOMREQUEST => $method,
		];
		if ($body !== null) {
			$headers[] = 'Content-Type: application/json';
			$opts[CURLOPT_POSTFIELDS] = json_encode($body);
		}
		$opts[CURLOPT_HTTPHEADER] = $headers;
		curl_setopt_array($ch, $opts);
		$response = curl_exec($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($response === false) {
			Minz_Log::warning('AiAssistant: service unreachable: ' . $err);
			return [0, ['detail' => 'assistant service unreachable: ' . $err]];
		}
		$decoded = json_decode((string) $response, true);
		return [$code, is_array($decoded) ? $decoded : null];
	}

	/** Pass a Server-Sent-Events stream from the service straight through to the browser. */
	private function serviceStream(string $path, array $body): void {
		$this->beginSSE();
		$status = 0;
		$errBody = '';
		$ch = curl_init($this->serviceUrl() . $path);
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'Accept: text/event-stream',
				'X-Assistant-Token: ' . $this->serviceToken(),
			],
			CURLOPT_POSTFIELDS => json_encode($body),
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 600,
			CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$status): int {
				if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
					$status = (int) $m[1];
				}
				return strlen($header);
			},
			CURLOPT_WRITEFUNCTION => static function ($ch, string $data) use (&$status, &$errBody): int {
				if ($status !== 0 && $status !== 200) {
					$errBody .= $data;
					return strlen($data);
				}
				echo $data;
				flush();
				return strlen($data);
			},
		]);
		$ok = curl_exec($ch);
		$err = curl_error($ch);
		curl_close($ch);
		if ($ok === false) {
			$this->sendSSE(['error' => 'assistant service unreachable: ' . $err]);
		} elseif ($status !== 200) {
			$decoded = json_decode($errBody, true);
			$msg = is_array($decoded) && isset($decoded['detail']) ? (string) $decoded['detail'] : "service error (HTTP {$status})";
			$this->sendSSE(['error' => $msg]);
		}
	}

	private function beginSSE(): void {
		header('Content-Type: text/event-stream');
		header('Cache-Control: no-cache');
		header('X-Accel-Buffering: no');
		while (ob_get_level()) {
			ob_end_flush();
		}
		flush();
	}

	private function sendSSE(array $frame): void {
		echo 'data: ' . json_encode($frame) . "\n\n";
		flush();
	}

	private function jsonOut(array $data, int $code = 200): void {
		http_response_code($code);
		header('Content-Type: application/json');
		echo json_encode($data);
	}

	// ── Hooks ────────────────────────────────────────────────────────────────

	/**
	 * FreshRSS overwrites `attributes` and `tags` wholesale when a feed republishes
	 * an entry. Re-merge everything the service wrote so scores survive updates.
	 */
	public function hookEntryBeforeUpdate(FreshRSS_Entry $entry): FreshRSS_Entry {
		try {
			$entryDAO = FreshRSS_Factory::createEntryDao();
			$existing = $entryDAO->searchByGuid($entry->feedId(), $entry->guid());
		} catch (Throwable $e) {
			Minz_Log::warning('AiAssistant: could not load existing entry on update: ' . $e->getMessage());
			return $entry;
		}
		if ($existing === null) {
			return $entry;
		}
		// The hash FreshRSS stores must stay the one computed from the feed data,
		// otherwise every refresh sees a "changed" entry. Setters like _tags()
		// reset it, and hash() includes tags and (with enclosures) attributes.
		$feedHash = $entry->hash();
		$old = $existing->attributes();
		$new = $entry->attributes();
		foreach (self::AI_KEYS as $key) {
			if (array_key_exists($key, $old) && !array_key_exists($key, $new)) {
				$entry->_attribute($key, $old[$key]);
			}
		}
		$oldTopics = array_values(array_filter($existing->tags(), static fn(string $t) => str_starts_with($t, self::TOPIC_PREFIX)));
		if ($oldTopics !== []) {
			$entry->_tags(array_values(array_unique(array_merge($entry->tags(), $oldTopics))));
		}
		$entry->_hash($feedHash);
		return $entry;
	}

	public function hookEntryBeforeDisplay(FreshRSS_Entry $entry): FreshRSS_Entry {
		$attrs = $entry->attributes();
		$entryId = htmlspecialchars((string) $entry->id(), ENT_QUOTES);

		if (!isset($attrs['ai_score'])) {
			// Not scored yet: the page script asks the service to score visible entries.
			$html = '<div class="ai-assistant-container ai-score-pending" data-entry-id="' . $entryId . '">'
				. '<span class="ai-scoring-status"></span>'
				. '<button class="ai-summarize-btn" data-force="1">Summarize</button>'
				. '<button class="ai-chat-btn">Chat</button>'
				. '</div>';
			$entry->_content($html . $entry->content());
			$this->injectTranscriptSection($entry);
			$this->injectFullContentSection($entry);
			return $entry;
		}

		$score = (int) $attrs['ai_score'];
		$reason = htmlspecialchars((string) ($attrs['ai_score_reason'] ?? ''), ENT_QUOTES);
		$summary = (string) ($attrs['ai_summary'] ?? '');
		$detail = (string) ($attrs['ai_detail'] ?? '');
		$isFull = !empty($attrs['ai_summary_full']);

		$colorClass = $score >= 7 ? 'ai-score-high' : ($score >= 4 ? 'ai-score-mid' : 'ai-score-low');

		$html = '<div class="ai-assistant-container" data-entry-id="' . $entryId . '">'
			. '<span class="ai-score-badge ' . $colorClass . '" title="' . $reason . '">' . $score . '</span>';
		if ($summary !== '') {
			$html .= '<span class="ai-summary">' . htmlspecialchars($summary) . '</span>';
		}
		if ($score > 0) {
			if ($summary === '' || !$isFull) {
				$html .= '<button class="ai-summarize-btn" data-force="1" title="Write a full summary from the whole text">'
					. ($summary === '' ? 'Summarize' : 'Full summary') . '</button>';
			}
			if ($detail !== '') {
				$html .= '<button class="ai-detail-toggle">More detail</button>'
					. '<div class="ai-detail" style="display:none;">' . $this->formatDetail($detail) . '</div>';
			} else {
				$html .= '<button class="ai-detail-btn">More detail</button>';
			}
		}
		$html .= '<button class="ai-chat-btn">Chat</button>'
			. '<button class="ai-feedback-btn" data-dir="more" title="More like this">+</button>'
			. '<button class="ai-feedback-btn" data-dir="less" title="Less like this">&minus;</button>'
			. '</div>';

		$entry->_content($html . $entry->content());
		$this->injectTranscriptSection($entry);
		$this->injectFullContentSection($entry);
		return $entry;
	}

	private function isYoutube(FreshRSS_Entry $entry): bool {
		return preg_match('#(?:youtube\.com/watch\?.*v=|youtu\.be/|youtube\.com/shorts/)[A-Za-z0-9_-]{11}#', $entry->link()) === 1;
	}

	private function injectTranscriptSection(FreshRSS_Entry $entry): void {
		if (!$this->isYoutube($entry)) {
			return;
		}
		$attrs = $entry->attributes();
		if (!empty($attrs['yt_is_short'])) {
			return;
		}
		$entryId = htmlspecialchars((string) $entry->id(), ENT_QUOTES);
		$transcript = $attrs['yt_transcript'] ?? null;
		if (is_string($transcript) && $transcript !== '') {
			$section = '<details class="ai-transcript-section"><summary>Video transcript</summary>'
				. '<div class="ai-transcript-content">' . nl2br(htmlspecialchars($transcript)) . '</div></details>';
			$entry->_content($entry->content() . $section);
		} elseif ($transcript === null) {
			$entry->_content($entry->content() . '<button class="ai-load-transcript-btn" data-entry-id="' . $entryId . '">Load transcript</button>');
		}
	}

	private function injectFullContentSection(FreshRSS_Entry $entry): void {
		$attrs = $entry->attributes();
		$cached = $attrs['full_content'] ?? null;
		if (is_string($cached) && $cached !== '') {
			$section = '<details class="ai-fullcontent-section"><summary>Full article (fetched)</summary>'
				. '<div class="ai-fullcontent-content">' . nl2br(htmlspecialchars($cached)) . '</div></details>';
			$entry->_content($entry->content() . $section);
		}
	}

	private function formatDetail(string $detail): string {
		$escaped = htmlspecialchars($detail);
		$formatted = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
		return nl2br($formatted);
	}

	// ── Config page + AJAX router ────────────────────────────────────────────

	#[\Override]
	public function handleConfigureAction(): void {
		$ajaxAction = Minz_Request::paramString('ajax_action');
		if ($ajaxAction !== '') {
			$this->handleAjax($ajaxAction);
			return;
		}

		if (Minz_Request::isPost()) {
			$config = $this->getUserConfiguration() ?: [];
			$config['assistant_url'] = trim(Minz_Request::paramString('assistant_url', true));
			$config['assistant_token'] = trim(Minz_Request::paramString('assistant_token', true));
			$config['assistant_public_url'] = trim(Minz_Request::paramString('assistant_public_url', true));
			// Legacy keys from v1 are no longer used
			unset($config['api_key'], $config['interest_profile'], $config['summary_threshold'], $config['scoring_model'], $config['summary_model']);
			$this->setUserConfiguration($config);
		}

		$this->serviceUrl = $this->serviceUrl();
		$this->publicUrl = $this->publicUrl();
		$this->tokenFromEnv = (string) getenv('ASSISTANT_INTERNAL_TOKEN') !== '' && (string) ($this->getUserConfigurationValue('assistant_token') ?? '') === '';
		$this->tokenConfigured = $this->serviceToken() !== '';
	}

	private function handleAjax(string $action): void {
		switch ($action) {
			case 'score_batch':
				$ids = self::jsonParam('entry_ids');
				if (!is_array($ids) || $ids === []) {
					$this->jsonOut(['status' => 'error', 'message' => 'No entry IDs']);
					break;
				}
				[$code, $data] = $this->serviceJson('POST', '/internal/score', ['entry_ids' => array_map('strval', $ids)], 300);
				if ($code !== 200 || $data === null) {
					$this->jsonOut(['status' => 'error', 'message' => $data['detail'] ?? "service error (HTTP {$code})"]);
					break;
				}
				$this->jsonOut($data);
				break;

			case 'summarize':
				$this->serviceStream('/internal/entries/' . rawurlencode((string) self::jsonParam('entry_id')) . '/summarize',
					['force' => (bool) self::jsonParam('force')]);
				break;

			case 'detail':
				$this->serviceStream('/internal/entries/' . rawurlencode((string) self::jsonParam('entry_id')) . '/detail',
					['force' => (bool) self::jsonParam('force')]);
				break;

			case 'chat':
				$this->serviceStream('/internal/entries/' . rawurlencode((string) self::jsonParam('entry_id')) . '/chat', [
					'message' => (string) self::jsonParam('message'),
					'model' => (string) self::jsonParam('model') ?: null,
				]);
				break;

			case 'chat_history':
				[$code, $data] = $this->serviceJson('GET', '/internal/entries/' . rawurlencode((string) self::jsonParam('entry_id')) . '/chat');
				$this->jsonOut($code === 200 && $data !== null ? $data + ['status' => 'ok'] : ['status' => 'error', 'messages' => []]);
				break;

			case 'feedback':
				[$code, $data] = $this->serviceJson('POST', '/internal/feedback', [
					'entry_id' => (string) self::jsonParam('entry_id'),
					'direction' => (string) self::jsonParam('direction'),
					'reason' => (string) self::jsonParam('reason'),
				], 180);
				$this->jsonOut($code === 200 && $data !== null ? $data : ['status' => 'error', 'message' => $data['detail'] ?? "service error (HTTP {$code})"]);
				break;

			case 'fetch_transcript':
				[$code, $data] = $this->serviceJson('POST', '/internal/entries/' . rawurlencode((string) self::jsonParam('entry_id')) . '/transcript', [], 90);
				$this->jsonOut($code === 200 && $data !== null ? $data : ['status' => 'error', 'message' => $data['detail'] ?? "service error (HTTP {$code})"]);
				break;

			case 'fetch_full_content':
				[$code, $data] = $this->serviceJson('POST', '/internal/entries/' . rawurlencode((string) self::jsonParam('entry_id')) . '/full_content', [], 60);
				$this->jsonOut($code === 200 && $data !== null ? $data : ['status' => 'error', 'message' => $data['detail'] ?? "service error (HTTP {$code})"]);
				break;

			case 'test_service':
				[$code, $data] = $this->serviceJson('GET', '/internal/health', null, 15);
				if ($code === 200 && $data !== null) {
					$this->jsonOut(['status' => 'ok', 'message' => 'Connected. ' . (int) ($data['pending'] ?? 0) . ' entries pending scoring.']);
				} else {
					$this->jsonOut(['status' => 'error', 'message' => $data['detail'] ?? "Cannot reach the assistant service (HTTP {$code})"]);
				}
				break;

			default:
				$this->jsonOut(['status' => 'error', 'message' => 'Unknown action'], 400);
		}
		exit;
	}
}
