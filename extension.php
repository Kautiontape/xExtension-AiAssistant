<?php

class AiAssistantExtension extends Minz_Extension {

	private static bool $metaInjected = false;

	// ── Lifecycle ────────────────────────────────────────────────────────────

	public function init(): void {
		$this->registerHook('entry_before_insert', [$this, 'hookEntryBeforeInsert']);
		$this->registerHook('entry_before_display', [$this, 'hookEntryBeforeDisplay']);
		Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
		Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
	}

	// ── Hooks ────────────────────────────────────────────────────────────────

	public function hookEntryBeforeInsert(FreshRSS_Entry $entry): FreshRSS_Entry {
		$entry->_attribute('ai_needs_scoring', true);
		return $entry;
	}

	public function hookEntryBeforeDisplay(FreshRSS_Entry $entry): FreshRSS_Entry {
		$attrs = $entry->attributes();
		$entryId = $entry->id();

		// Unscored: emit placeholder for JS batch scoring
		if (!isset($attrs['ai_score'])) {
			$placeholder = '<div class="ai-assistant-container ai-score-pending"'
				. ' data-entry-id="' . htmlspecialchars($entryId) . '"></div>';
			$entry->_content($placeholder . $entry->content());
			return $entry;
		}

		$score = intval($attrs['ai_score']);
		$reason = htmlspecialchars($attrs['ai_score_reason'] ?? '', ENT_QUOTES);
		$summary = $attrs['ai_summary'] ?? '';
		$threshold = intval($this->getUserConfigurationValue('summary_threshold') ?: 7);

		if ($score >= 7) {
			$colorClass = 'ai-score-high';
		} elseif ($score >= 4) {
			$colorClass = 'ai-score-mid';
		} else {
			$colorClass = 'ai-score-low';
		}

		// Inject CSRF meta once
		$meta = '';
		if (!self::$metaInjected) {
			self::$metaInjected = true;
			$token = FreshRSS_Auth::csrfToken();
			$meta = '<div id="ai-assistant-meta" data-token="'
				. htmlspecialchars($token) . '" style="display:none"></div>';
		}

		// Build badge
		$html = $meta
			. '<div class="ai-assistant-container" data-entry-id="' . htmlspecialchars($entryId) . '">'
			. '<span class="ai-score-badge ' . $colorClass . '" title="' . $reason . '">'
			. $score . '</span>';

		// Summary or summarize button
		if ($summary) {
			$html .= '<span class="ai-summary">' . htmlspecialchars($summary) . '</span>';
		} elseif ($score < $threshold) {
			$html .= '<button class="ai-summarize-btn">Summarize</button>';
		}

		// Feedback buttons
		$html .= '<button class="ai-feedback-btn" data-dir="more" title="More like this">+</button>'
			. '<button class="ai-feedback-btn" data-dir="less" title="Less like this">&minus;</button>'
			. '</div>';

		$entry->_content($html . $entry->content());
		return $entry;
	}

	// ── Config page + AJAX router ────────────────────────────────────────────

	public function handleConfigureAction(): void {
		$ajaxAction = Minz_Request::paramString('ajax_action');
		if ($ajaxAction) {
			$this->handleAjax($ajaxAction);
			return;
		}

		// Normal POST: save settings
		if (Minz_Request::isPost()) {
			$config = $this->getUserConfiguration() ?: [];
			$config['api_key'] = Minz_Request::paramString('api_key');
			$config['interest_profile'] = Minz_Request::paramString('interest_profile');
			$config['summary_threshold'] = Minz_Request::paramString('summary_threshold');
			$config['scoring_model'] = Minz_Request::paramString('scoring_model');
			$config['summary_model'] = Minz_Request::paramString('summary_model');
			$this->setUserConfiguration($config);
		}
	}

	// ── AJAX handlers ────────────────────────────────────────────────────────

	private function handleAjax(string $action): void {
		header('Content-Type: application/json');

		switch ($action) {
			case 'score_batch':
				$this->ajaxScoreBatch();
				break;
			case 'summarize':
				$this->ajaxSummarize();
				break;
			case 'feedback':
				$this->ajaxFeedback();
				break;
			case 'test_api_key':
				$this->ajaxTestApiKey();
				break;
			case 'get_scored_entries':
				$this->ajaxGetScoredEntries();
				break;
			case 'get_profile':
				$this->ajaxGetProfile();
				break;
			default:
				echo json_encode(['status' => 'error', 'message' => 'Unknown action']);
		}
		exit;
	}

	private function ajaxScoreBatch(): void {
		$entryIds = json_decode(Minz_Request::paramString('entry_ids'), true);
		if (!is_array($entryIds) || empty($entryIds)) {
			echo json_encode(['status' => 'error', 'message' => 'No entry IDs']);
			return;
		}

		$apiKey = $this->getUserConfigurationValue('api_key');
		$profile = $this->getUserConfigurationValue('interest_profile');
		$model = $this->getUserConfigurationValue('scoring_model') ?: 'claude-haiku-4-5-20251001';

		if (!$apiKey) {
			echo json_encode(['status' => 'error', 'message' => 'No API key configured']);
			return;
		}

		// Load entries
		$entryDAO = FreshRSS_Factory::createEntryDao();
		$entries = [];
		$articlesForPrompt = [];

		foreach ($entryIds as $id) {
			$entry = $entryDAO->searchById($id);
			if ($entry) {
				$entries[$id] = $entry;
				$articlesForPrompt[] = [
					'id' => $id,
					'title' => $entry->title(),
					'source' => $entry->feed(false) ? $entry->feed(false)->name() : 'Unknown',
					'summary' => mb_substr(strip_tags($entry->content()), 0, 300),
				];
			}
		}

		if (empty($articlesForPrompt)) {
			echo json_encode(['status' => 'ok', 'scores' => []]);
			return;
		}

		$prompt = "Score these articles for relevance based on the interest profile below.\n\n"
			. "<interest_profile>\n{$profile}\n</interest_profile>\n\n"
			. "<articles>\n" . json_encode($articlesForPrompt, JSON_PRETTY_PRINT) . "\n</articles>\n\n"
			. "For each article, return a JSON array of objects with:\n"
			. "- \"id\": the article id (string)\n"
			. "- \"score\": 1-10 relevance score (10 = must read, 1 = irrelevant)\n"
			. "- \"reason\": one sentence explaining the score\n\n"
			. "Return ONLY the JSON array, no markdown fences, no other text.";

		$response = $this->callClaude($apiKey, $model, $prompt, 2000);
		if ($response === null) {
			echo json_encode(['status' => 'error', 'message' => 'Claude API call failed']);
			return;
		}

		$scores = $this->parseJsonResponse($response);
		if (!is_array($scores)) {
			echo json_encode(['status' => 'error', 'message' => 'Failed to parse scores']);
			return;
		}

		// Write scores to entry attributes
		$results = [];
		$threshold = intval($this->getUserConfigurationValue('summary_threshold') ?: 7);
		$summaryModel = $this->getUserConfigurationValue('summary_model') ?: 'claude-sonnet-4-20250514';
		$highScoreEntries = [];

		foreach ($scores as $s) {
			$id = $s['id'] ?? null;
			if ($id === null || !isset($entries[$id])) continue;

			$entry = $entries[$id];
			$score = intval($s['score'] ?? 5);
			$reason = $s['reason'] ?? '';

			$entry->_attribute('ai_score', $score);
			$entry->_attribute('ai_score_reason', $reason);
			$entry->_attribute('ai_needs_scoring', null);

			$entryDAO->updateEntry($entry->toArray());

			$results[] = [
				'id' => $id,
				'score' => $score,
				'reason' => $reason,
			];

			if ($score >= $threshold) {
				$highScoreEntries[] = ['id' => $id, 'entry' => $entry, 'score' => $score];
			}
		}

		// Auto-summarize high-score articles
		if (!empty($highScoreEntries)) {
			$this->autoSummarize($apiKey, $summaryModel, $highScoreEntries, $entryDAO, $results);
		}

		echo json_encode(['status' => 'ok', 'scores' => $results]);
	}

	private function autoSummarize(
		string $apiKey, string $model, array $highScoreEntries,
		$entryDAO, array &$results
	): void {
		foreach ($highScoreEntries as $item) {
			$entry = $item['entry'];
			$content = mb_substr(strip_tags($entry->content()), 0, 2000);
			$title = $entry->title();

			$prompt = "Summarize this article in one concise sentence that captures the key insight.\n\n"
				. "Title: {$title}\n\nContent:\n{$content}\n\n"
				. "Return ONLY the summary sentence, no quotes, no prefix.";

			$summary = $this->callClaude($apiKey, $model, $prompt, 200);
			if ($summary) {
				$entry->_attribute('ai_summary', trim($summary));
				$entryDAO->updateEntry($entry->toArray());

				// Add summary to results
				foreach ($results as &$r) {
					if ($r['id'] == $item['id']) {
						$r['summary'] = trim($summary);
						break;
					}
				}
				unset($r);
			}
		}
	}

	private function ajaxSummarize(): void {
		$entryId = Minz_Request::paramString('entry_id');
		if (!$entryId) {
			echo json_encode(['status' => 'error', 'message' => 'No entry ID']);
			return;
		}

		$apiKey = $this->getUserConfigurationValue('api_key');
		$model = $this->getUserConfigurationValue('summary_model') ?: 'claude-sonnet-4-20250514';

		if (!$apiKey) {
			echo json_encode(['status' => 'error', 'message' => 'No API key configured']);
			return;
		}

		$entryDAO = FreshRSS_Factory::createEntryDao();
		$entry = $entryDAO->searchById($entryId);
		if (!$entry) {
			echo json_encode(['status' => 'error', 'message' => 'Entry not found']);
			return;
		}

		$content = mb_substr(strip_tags($entry->content()), 0, 2000);
		$title = $entry->title();

		$prompt = "Summarize this article in one concise sentence that captures the key insight.\n\n"
			. "Title: {$title}\n\nContent:\n{$content}\n\n"
			. "Return ONLY the summary sentence, no quotes, no prefix.";

		$summary = $this->callClaude($apiKey, $model, $prompt, 200);
		if ($summary === null) {
			echo json_encode(['status' => 'error', 'message' => 'Claude API call failed']);
			return;
		}

		$summary = trim($summary);
		$entry->_attribute('ai_summary', $summary);
		$entryDAO->updateEntry($entry->toArray());

		echo json_encode(['status' => 'ok', 'summary' => $summary]);
	}

	private function ajaxFeedback(): void {
		$entryId = Minz_Request::paramString('entry_id');
		$direction = Minz_Request::paramString('direction');
		$reason = Minz_Request::paramString('reason');

		if (!$entryId || !$direction) {
			echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
			return;
		}

		$apiKey = $this->getUserConfigurationValue('api_key');
		$model = $this->getUserConfigurationValue('summary_model') ?: 'claude-sonnet-4-20250514';
		$profile = $this->getUserConfigurationValue('interest_profile');

		if (!$apiKey || !$profile) {
			echo json_encode(['status' => 'error', 'message' => 'API key and profile required']);
			return;
		}

		// Look up article title
		$title = 'Unknown';
		try {
			$entryDAO = FreshRSS_Factory::createEntryDao();
			$entry = $entryDAO->searchById($entryId);
			if ($entry) {
				$title = $entry->title();
			}
		} catch (Exception $e) {
			// Use fallback title
		}

		$label = $direction === 'more' ? 'MORE' : 'LESS';
		$prompt = "Here is the user's interest profile:\n\n"
			. "<profile>\n{$profile}\n</profile>\n\n"
			. "They said they want {$label} of articles like \"{$title}\""
			. ($reason ? " because \"{$reason}\"" : "") . ".\n\n"
			. "Update the interest profile to reflect this. If a similar interest already exists, "
			. "adjust its intensity or wording. If it's new, add it in the appropriate section. "
			. "Return the complete updated profile text, nothing else.";

		$newProfile = $this->callClaude($apiKey, $model, $prompt, 2000);
		if ($newProfile === null) {
			echo json_encode(['status' => 'error', 'message' => 'Profile rewrite failed']);
			return;
		}

		$newProfile = trim($newProfile);
		$config = $this->getUserConfiguration() ?: [];
		$config['interest_profile'] = $newProfile;
		$this->setUserConfiguration($config);

		echo json_encode(['status' => 'ok', 'profile_changed' => true]);
	}

	private function ajaxTestApiKey(): void {
		$apiKey = Minz_Request::paramString('api_key');
		if (!$apiKey) {
			$apiKey = $this->getUserConfigurationValue('api_key');
		}

		if (!$apiKey) {
			echo json_encode(['status' => 'error', 'message' => 'No API key provided']);
			return;
		}

		$response = $this->callClaude($apiKey, 'claude-haiku-4-5-20251001', 'Say "ok"', 10);
		if ($response !== null) {
			echo json_encode(['status' => 'ok', 'message' => 'API key is valid']);
		} else {
			echo json_encode(['status' => 'error', 'message' => 'API call failed — check your key']);
		}
	}

	private function ajaxGetScoredEntries(): void {
		$sinceHours = intval(Minz_Request::paramString('since') ?: 24);
		$since = time() - ($sinceHours * 3600);

		$entryDAO = FreshRSS_Factory::createEntryDao();

		// Use FreshRSS search to get recent entries
		$search = new FreshRSS_BooleanSearch('');
		$entries = $entryDAO->listWhere('A', $search, FreshRSS_Entry::STATE_ALL, 'DESC', 500);

		$results = [];
		foreach ($entries as $entry) {
			if ($entry->date(true) < $since) continue;

			$attrs = $entry->attributes();
			if (!isset($attrs['ai_score'])) continue;

			$results[] = [
				'id' => $entry->id(),
				'title' => $entry->title(),
				'score' => intval($attrs['ai_score']),
				'reason' => $attrs['ai_score_reason'] ?? '',
				'summary' => $attrs['ai_summary'] ?? '',
				'url' => $entry->link(),
				'date' => date('c', $entry->date(true)),
				'source' => $entry->feed(false) ? $entry->feed(false)->name() : 'Unknown',
			];
		}

		echo json_encode(['status' => 'ok', 'entries' => $results]);
	}

	private function ajaxGetProfile(): void {
		$profile = $this->getUserConfigurationValue('interest_profile') ?: '';
		echo json_encode(['status' => 'ok', 'profile' => $profile]);
	}

	// ── Claude API ───────────────────────────────────────────────────────────

	private function callClaude(string $apiKey, string $model, string $prompt, int $maxTokens): ?string {
		$ch = curl_init('https://api.anthropic.com/v1/messages');
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'x-api-key: ' . $apiKey,
				'anthropic-version: 2023-06-01',
			],
			CURLOPT_POSTFIELDS => json_encode([
				'model' => $model,
				'max_tokens' => $maxTokens,
				'messages' => [['role' => 'user', 'content' => $prompt]],
			]),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 60,
		]);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode !== 200 || !$response) {
			Minz_Log::error("AiAssistant: Claude API error (HTTP {$httpCode}): " . ($response ?: 'no response'));
			return null;
		}

		$data = json_decode($response, true);
		return $data['content'][0]['text'] ?? null;
	}

	private function parseJsonResponse(string $text): ?array {
		$text = trim($text);
		// Strip markdown fences if present
		if (str_starts_with($text, '```')) {
			$text = substr($text, strpos($text, "\n") + 1);
			if (str_ends_with($text, '```')) {
				$text = substr($text, 0, -3);
			}
			$text = trim($text);
		}
		$result = json_decode($text, true);
		return is_array($result) ? $result : null;
	}
}
