<?php

class AiAssistantExtension extends Minz_Extension {

	private static ?array $jsonInput = null;

	private static function jsonParam(string $key): mixed {
		if (self::$jsonInput === null) {
			self::$jsonInput = json_decode(file_get_contents('php://input'), true) ?: [];
		}
		return self::$jsonInput[$key] ?? '';
	}

	// ── Lifecycle ────────────────────────────────────────────────────────────

	public function init(): void {
		$this->registerHook('entry_before_insert', [$this, 'hookEntryBeforeInsert']);
		$this->registerHook('entry_before_display', [$this, 'hookEntryBeforeDisplay']);
		Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
		Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
	}

	// ── Hooks ────────────────────────────────────────────────────────────────

	public function hookEntryBeforeInsert(FreshRSS_Entry $entry): FreshRSS_Entry {
		if ($this->shouldScore($entry)) {
			$entry->_attribute('ai_needs_scoring', true);
		}
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
		$detail = $attrs['ai_detail'] ?? '';
		$threshold = intval($this->getUserConfigurationValue('summary_threshold') ?: 5);

		if ($score >= 7) {
			$colorClass = 'ai-score-high';
		} elseif ($score >= 4) {
			$colorClass = 'ai-score-mid';
		} else {
			$colorClass = 'ai-score-low';
		}

		// Build badge
		$html = '<div class="ai-assistant-container" data-entry-id="' . htmlspecialchars($entryId) . '">'
			. '<span class="ai-score-badge ' . $colorClass . '" title="' . $reason . '">'
			. $score . '</span>';

		// Summary or summarize button
		if ($summary) {
			$html .= '<span class="ai-summary">' . htmlspecialchars($summary) . '</span>';
			// "More detail" button or cached detail
			if ($detail) {
				$html .= '<button class="ai-detail-toggle">More detail</button>'
					. '<div class="ai-detail" style="display:none;">' . $this->formatDetail($detail) . '</div>';
			} else {
				$html .= '<button class="ai-detail-btn">More detail</button>';
			}
		} elseif ($score < $threshold) {
			$html .= '<button class="ai-summarize-btn">Summarize</button>';
		}

		// Chat + Feedback buttons
		$html .= '<button class="ai-chat-btn">Chat</button>'
			. '<button class="ai-feedback-btn" data-dir="more" title="More like this">+</button>'
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

		// Load categories/feeds for the config template
		$catDAO = FreshRSS_Factory::createCategoryDao();
		$this->categories = $catDAO->listCategories(true, false) ?: [];

		// Load current per-feed/category config
		$this->summarizeFeeds = $this->loadAttribute('ext_ai_assistant_summarize_feeds');
		$this->summarizeCategories = $this->loadAttribute('ext_ai_assistant_summarize_categories');
		$this->scoreFeeds = $this->loadAttribute('ext_ai_assistant_score_feeds');
		$this->scoreCategories = $this->loadAttribute('ext_ai_assistant_score_categories');

		// Normal POST: save settings
		if (Minz_Request::isPost()) {
			$config = $this->getUserConfiguration() ?: [];
			$config['api_key'] = Minz_Request::paramString('api_key');
			$config['interest_profile'] = Minz_Request::paramString('interest_profile');
			$config['summary_threshold'] = Minz_Request::paramString('summary_threshold');
			$config['scoring_model'] = Minz_Request::paramString('scoring_model');
			$config['summary_model'] = Minz_Request::paramString('summary_model');
			$this->setUserConfiguration($config);

			// Save per-feed/category checkboxes (score + summarize)
			$sumFeedConfig = [];
			$sumCatConfig = [];
			$scoreFeedConfig = [];
			$scoreCatConfig = [];
			foreach ($this->categories as $c) {
				if (Minz_Request::paramBoolean('sum_cat_' . $c->id())) {
					$sumCatConfig[$c->id()] = true;
				}
				if (Minz_Request::paramBoolean('score_cat_' . $c->id())) {
					$scoreCatConfig[$c->id()] = true;
				}
				foreach ($c->feeds() as $f) {
					if (Minz_Request::paramBoolean('sum_feed_' . $f->id())) {
						$sumFeedConfig[$f->id()] = true;
					}
					if (Minz_Request::paramBoolean('score_feed_' . $f->id())) {
						$scoreFeedConfig[$f->id()] = true;
					}
				}
			}
			FreshRSS_Context::userConf()->_attribute('ext_ai_assistant_summarize_feeds', json_encode($sumFeedConfig));
			FreshRSS_Context::userConf()->_attribute('ext_ai_assistant_summarize_categories', json_encode($sumCatConfig));
			FreshRSS_Context::userConf()->_attribute('ext_ai_assistant_score_feeds', json_encode($scoreFeedConfig));
			FreshRSS_Context::userConf()->_attribute('ext_ai_assistant_score_categories', json_encode($scoreCatConfig));
			FreshRSS_Context::userConf()->save();

			// Reload for display
			$this->summarizeFeeds = $sumFeedConfig;
			$this->summarizeCategories = $sumCatConfig;
			$this->scoreFeeds = $scoreFeedConfig;
			$this->scoreCategories = $scoreCatConfig;
		}
	}

	/** @var FreshRSS_Category[] */
	public array $categories = [];
	public array $summarizeFeeds = [];
	public array $summarizeCategories = [];
	public array $scoreFeeds = [];
	public array $scoreCategories = [];

	public function getSummarizeFeed(int $id): bool {
		return isset($this->summarizeFeeds[$id]);
	}

	public function getSummarizeCategory(int $id): bool {
		return isset($this->summarizeCategories[$id]);
	}

	public function getScoreFeed(int $id): bool {
		return isset($this->scoreFeeds[$id]);
	}

	public function getScoreCategory(int $id): bool {
		return isset($this->scoreCategories[$id]);
	}

	private function loadAttribute(string $key): array {
		$value = FreshRSS_Context::userConf()->attributeString($key);
		if ($value === '') return [];
		$decoded = json_decode($value, true);
		return is_array($decoded) ? $decoded : [];
	}

	private function shouldScore(FreshRSS_Entry $entry): bool {
		$feedId = $entry->feedId();
		$feed = $entry->feed(false);
		$catId = $feed ? ($feed->category() ? $feed->category()->id() : null) : null;

		$feedConfig = $this->loadAttribute('ext_ai_assistant_score_feeds');
		$catConfig = $this->loadAttribute('ext_ai_assistant_score_categories');

		return isset($feedConfig[$feedId]) || ($catId !== null && isset($catConfig[$catId]));
	}

	private function isAlwaysSummarize(FreshRSS_Entry $entry): bool {
		$feedId = $entry->feedId();
		$feed = $entry->feed(false);
		$catId = $feed ? ($feed->category() ? $feed->category()->id() : null) : null;

		$feedConfig = $this->loadAttribute('ext_ai_assistant_summarize_feeds');
		$catConfig = $this->loadAttribute('ext_ai_assistant_summarize_categories');

		return isset($feedConfig[$feedId]) || ($catId !== null && isset($catConfig[$catId]));
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
			case 'detail':
				$this->ajaxDetail();
				break;
			case 'feedback':
				$this->ajaxFeedback();
				break;
			case 'chat':
				$this->ajaxChat();
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
		$entryIds = self::jsonParam('entry_ids');
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
		$threshold = intval($this->getUserConfigurationValue('summary_threshold') ?: 5);
		$summaryModel = $this->getUserConfigurationValue('summary_model') ?: 'claude-sonnet-4-6';
		$summaryEntries = [];

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

			// Auto-summarize if score >= threshold OR always-summarize feed/category
			if ($score >= $threshold || $this->isAlwaysSummarize($entry)) {
				$summaryEntries[] = ['id' => $id, 'entry' => $entry, 'score' => $score];
			}
		}

		// Auto-summarize qualifying articles
		if (!empty($summaryEntries)) {
			$this->autoSummarize($apiKey, $summaryModel, $summaryEntries, $entryDAO, $results);
		}

		echo json_encode(['status' => 'ok', 'scores' => $results]);
	}

	// ── Summary generation (shared) ─────────────────────────────────────────

	private function generateSummary(string $apiKey, string $model, FreshRSS_Entry $entry): ?string {
		$profile = $this->getUserConfigurationValue('interest_profile');
		$content = mb_substr(strip_tags($entry->content()), 0, 3000);
		$title = $entry->title();
		$source = $entry->feed(false) ? $entry->feed(false)->name() : 'Unknown';

		$prompt = "You are a research assistant for a reader with these interests:\n\n"
			. "<interest_profile>\n{$profile}\n</interest_profile>\n\n"
			. "Summarize this article — what it says and why it matters to this reader.\n"
			. "Be direct. 1-3 sentences. If it's tangential to their interests, say so.\n\n"
			. "Title: {$title}\nSource: {$source}\n\nContent:\n{$content}\n\n"
			. "Return ONLY the summary, no quotes, no prefix.";

		$summary = $this->callClaude($apiKey, $model, $prompt, 300);
		return $summary ? trim($summary) : null;
	}

	private function autoSummarize(
		string $apiKey, string $model, array $summaryEntries,
		$entryDAO, array &$results
	): void {
		foreach ($summaryEntries as $item) {
			$entry = $item['entry'];
			$summary = $this->generateSummary($apiKey, $model, $entry);
			if ($summary) {
				$entry->_attribute('ai_summary', $summary);
				$entryDAO->updateEntry($entry->toArray());

				foreach ($results as &$r) {
					if ($r['id'] == $item['id']) {
						$r['summary'] = $summary;
						break;
					}
				}
				unset($r);
			}
		}
	}

	private function ajaxSummarize(): void {
		$entryId = self::jsonParam('entry_id');
		if (!$entryId) {
			echo json_encode(['status' => 'error', 'message' => 'No entry ID']);
			return;
		}

		$apiKey = $this->getUserConfigurationValue('api_key');
		$model = $this->getUserConfigurationValue('summary_model') ?: 'claude-sonnet-4-6';

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

		$summary = $this->generateSummary($apiKey, $model, $entry);
		if ($summary === null) {
			echo json_encode(['status' => 'error', 'message' => 'Claude API call failed']);
			return;
		}

		$entry->_attribute('ai_summary', $summary);
		$entryDAO->updateEntry($entry->toArray());

		echo json_encode(['status' => 'ok', 'summary' => $summary]);
	}

	// ── Detail generation ───────────────────────────────────────────────────

	private function ajaxDetail(): void {
		$entryId = self::jsonParam('entry_id');
		if (!$entryId) {
			echo json_encode(['status' => 'error', 'message' => 'No entry ID']);
			return;
		}

		$apiKey = $this->getUserConfigurationValue('api_key');
		$model = $this->getUserConfigurationValue('summary_model') ?: 'claude-sonnet-4-6';

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

		// Return cached detail if available
		$cached = $entry->attributes()['ai_detail'] ?? null;
		if ($cached) {
			echo json_encode(['status' => 'ok', 'detail' => $cached]);
			return;
		}

		$profile = $this->getUserConfigurationValue('interest_profile');
		$content = mb_substr(strip_tags($entry->content()), 0, 6000);
		$title = $entry->title();
		$source = $entry->feed(false) ? $entry->feed(false)->name() : 'Unknown';

		$prompt = "Break down this article for a reader with these interests:\n\n"
			. "<interest_profile>\n{$profile}\n</interest_profile>\n\n"
			. "Provide a structured breakdown in 3-6 sections. Each section gets a bold\n"
			. "subtitle and 1-2 sentences of detail. Spend more time on sections relevant\n"
			. "to the reader's interests. Don't skip sections, but be brief on less\n"
			. "relevant parts.\n\n"
			. "Title: {$title}\nSource: {$source}\n\nContent:\n{$content}\n\n"
			. "Format: **Section Title** on its own line, then detail paragraph. No\n"
			. "markdown headers or bullets.";

		$detail = $this->callClaude($apiKey, $model, $prompt, 1500);
		if ($detail === null) {
			echo json_encode(['status' => 'error', 'message' => 'Claude API call failed']);
			return;
		}

		$detail = trim($detail);
		$entry->_attribute('ai_detail', $detail);
		$entryDAO->updateEntry($entry->toArray());

		echo json_encode(['status' => 'ok', 'detail' => $detail]);
	}

	private function formatDetail(string $detail): string {
		$escaped = htmlspecialchars($detail);
		// Convert **Title** to <strong>Title</strong>
		$formatted = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
		// Convert newlines to <br>
		return nl2br($formatted);
	}

	// ── Feedback ─────────────────────────────────────────────────────────────

	private function ajaxFeedback(): void {
		$entryId = self::jsonParam('entry_id');
		$direction = self::jsonParam('direction');
		$reason = self::jsonParam('reason');

		if (!$entryId || !$direction) {
			echo json_encode(['status' => 'error', 'message' => 'Missing parameters']);
			return;
		}

		$apiKey = $this->getUserConfigurationValue('api_key');
		$model = $this->getUserConfigurationValue('summary_model') ?: 'claude-sonnet-4-6';
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
		$apiKey = self::jsonParam('api_key');
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
		$sinceHours = intval(self::jsonParam('since') ?: 24);
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

	// ── Chat ────────────────────────────────────────────────────────────────

	private function ajaxChat(): void {
		$entryId = self::jsonParam('entry_id');
		$message = self::jsonParam('message');
		$model = self::jsonParam('model');

		if (!$entryId || !$message) {
			echo json_encode(['status' => 'error', 'message' => 'Missing entry_id or message']);
			return;
		}

		$apiKey = $this->getUserConfigurationValue('api_key');
		if (!$model) {
			$model = $this->getUserConfigurationValue('summary_model') ?: 'claude-sonnet-4-6';
		}

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

		$profile = $this->getUserConfigurationValue('interest_profile');
		$content = mb_substr(strip_tags($entry->content()), 0, 6000);
		$title = $entry->title();
		$source = $entry->feed(false) ? $entry->feed(false)->name() : 'Unknown';
		$summary = $entry->attributes()['ai_summary'] ?? '';
		$detail = $entry->attributes()['ai_detail'] ?? '';

		// Build system prompt with article context
		$system = "You are a research assistant helping a reader understand an article.\n\n"
			. "Article: {$title}\nSource: {$source}\n\n"
			. "<article_content>\n{$content}\n</article_content>";

		if ($profile) {
			$system .= "\n\n<reader_interests>\n{$profile}\n</reader_interests>";
		}
		if ($summary) {
			$system .= "\n\nPrevious summary: {$summary}";
		}
		if ($detail) {
			$system .= "\n\nPrevious detail breakdown:\n{$detail}";
		}

		$system .= "\n\nAnswer questions about this article. Start with what the article says. "
			. "If the article doesn't fully answer the question, supplement with your own knowledge "
			. "or search the web — but clearly note when you're going beyond the article's content. "
			. "Be concise and direct.";

		// Load existing chat history
		$chatHistory = $entry->attributes()['ai_chat'] ?? [];
		if (!is_array($chatHistory)) {
			$chatHistory = [];
		}

		// Build messages array: prior turns + new message
		$messages = [];
		foreach ($chatHistory as $turn) {
			$messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
		}
		$messages[] = ['role' => 'user', 'content' => $message];

		$response = $this->callClaudeMessages($apiKey, $model, $system, $messages, 1500, true);
		if ($response === null) {
			echo json_encode(['status' => 'error', 'message' => 'Claude API call failed']);
			return;
		}

		$response = trim($response);

		// Append to chat history and save
		$chatHistory[] = ['role' => 'user', 'content' => $message];
		$chatHistory[] = ['role' => 'assistant', 'content' => $response];
		$entry->_attribute('ai_chat', $chatHistory);
		$entryDAO->updateEntry($entry->toArray());

		echo json_encode(['status' => 'ok', 'response' => $response, 'history' => $chatHistory]);
	}

	// ── Claude API ───────────────────────────────────────────────────────────

	private function callClaudeMessages(string $apiKey, string $model, string $system, array $messages, int $maxTokens, bool $webSearch = false): ?string {
		$body = [
			'model' => $model,
			'max_tokens' => $maxTokens,
			'messages' => $messages,
		];
		if ($system !== '') {
			$body['system'] = $system;
		}
		if ($webSearch) {
			$body['tools'] = [
				['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 3],
			];
		}

		$ch = curl_init('https://api.anthropic.com/v1/messages');
		curl_setopt_array($ch, [
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => [
				'Content-Type: application/json',
				'x-api-key: ' . $apiKey,
				'anthropic-version: 2023-06-01',
			],
			CURLOPT_POSTFIELDS => json_encode($body),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 90,
		]);

		$response = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($httpCode !== 200 || !$response) {
			Minz_Log::error("AiAssistant: Claude API error (HTTP {$httpCode}): " . ($response ?: 'no response'));
			return null;
		}

		$data = json_decode($response, true);

		// With tools, response has multiple content blocks — extract all text
		$text = '';
		foreach ($data['content'] ?? [] as $block) {
			if (($block['type'] ?? '') === 'text') {
				$text .= $block['text'];
			}
		}
		return $text ?: null;
	}

	private function callClaude(string $apiKey, string $model, string $prompt, int $maxTokens): ?string {
		return $this->callClaudeMessages($apiKey, $model, '', [['role' => 'user', 'content' => $prompt]], $maxTokens);
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
