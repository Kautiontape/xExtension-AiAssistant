"use strict";

(function () {
	var EXT_NAME = "AI Assistant";
	var CHUNK_SIZE = 20;

	function getAjaxUrl(action) {
		var url = new URL(window.location.pathname, window.location.origin);
		url.searchParams.set("c", "extension");
		url.searchParams.set("a", "configure");
		url.searchParams.set("e", EXT_NAME);
		url.searchParams.set("ajax_action", action);
		return url.toString();
	}

	function ajaxPost(action, params) {
		params._csrf = context.csrf;
		params.ajax = true;
		return fetch(getAjaxUrl(action), {
			method: "POST",
			headers: { "Content-Type": "application/json; charset=UTF-8" },
			body: JSON.stringify(params),
		}).then(function (r) { return r.json(); });
	}

	// ── Streaming helper (SSE frames: {text}, {status}, {error}, {done}) ─────

	function streamPost(action, params, onText, onDone, onError, onStatus) {
		params._csrf = context.csrf;
		params.ajax = true;
		fetch(getAjaxUrl(action), {
			method: "POST",
			headers: { "Content-Type": "application/json; charset=UTF-8" },
			body: JSON.stringify(params),
		})
			.then(function (r) {
				var ct = r.headers.get("content-type") || "";
				if (ct.indexOf("text/event-stream") === -1) {
					return r.json().then(function (data) {
						if (data.status === "ok") onDone(data);
						else onError(data.message || "Request failed");
					});
				}
				var reader = r.body.getReader();
				var decoder = new TextDecoder();
				var buffer = "";
				var finished = false;
				function pump() {
					reader.read().then(function (result) {
						if (result.done) {
							if (!finished) { finished = true; onDone(null); }
							return;
						}
						buffer += decoder.decode(result.value, { stream: true });
						var parts = buffer.split("\n\n");
						buffer = parts.pop();
						parts.forEach(function (frame) {
							var match = frame.match(/^data:\s*(.+)$/m);
							if (!match) return;
							try {
								var evt = JSON.parse(match[1]);
								if (evt.error) { finished = true; onError(evt.error); }
								else if (evt.text) onText(evt.text);
								else if (evt.status && onStatus) onStatus(evt.status);
								else if (evt.done) { finished = true; onDone(evt); }
							} catch (e) { /* ignore */ }
						});
						pump();
					});
				}
				pump();
			})
			.catch(function () { onError("Request failed"); });
	}

	function escapeHtml(str) {
		return String(str)
			.replace(/&/g, "&amp;")
			.replace(/</g, "&lt;")
			.replace(/>/g, "&gt;")
			.replace(/"/g, "&quot;");
	}

	// Markdown rendering (marked + DOMPurify are loaded by the extension; fall back to the simple formatter)
	function renderMarkdown(text, breaks) {
		if (window.marked && window.DOMPurify) {
			try {
				var html = DOMPurify.sanitize(marked.parse(text || "", { gfm: true, breaks: !!breaks }), { ADD_ATTR: ["target"] });
				var tpl = document.createElement("template");
				tpl.innerHTML = html;
				tpl.content.querySelectorAll("a[href]").forEach(function (a) { a.target = "_blank"; a.rel = "noopener"; });
				return tpl.innerHTML;
			} catch (e) { /* fall through */ }
		}
		return formatRich(text);
	}

	// Re-render a streaming element at most once per animation frame
	function liveRenderer(el, breaks) {
		var pending = false, latest = "", finished = false;
		return function (text, done) {
			latest = text; finished = !!done;
			if (pending) return;
			pending = true;
			requestAnimationFrame(function () {
				pending = false;
				el.innerHTML = renderMarkdown(latest, breaks);
				el.classList.toggle("ai-chat-cursor", !finished);
			});
		};
	}

	function formatRich(text) {
		// Minimal markdown: **bold**, `code`, [links](url), line breaks, simple bullets
		var escaped = escapeHtml(text);
		escaped = escaped.replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>");
		escaped = escaped.replace(/`([^`]+)`/g, "<code>$1</code>");
		escaped = escaped.replace(/\[([^\]]+)\]\((https?:[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');
		escaped = escaped.replace(/^\s*[-*]\s+(.*)$/gm, "&bull; $1");
		return escaped.replace(/\n/g, "<br>");
	}

	function actionButtons(extra) {
		return (extra || "") +
			'<button class="ai-summarize-btn" data-force="1">Summarize</button>' +
			'<button class="ai-chat-btn">Chat</button>';
	}

	// ── Score visible pending entries via the service ──────────────────────

	function scorePendingEntries() {
		var pending = document.querySelectorAll(".ai-score-pending");
		if (!pending.length) return;
		var ids = [], elMap = {};
		pending.forEach(function (el) {
			var id = el.dataset.entryId;
			if (id && !elMap[id]) { ids.push(id); elMap[id] = el; }
		});
		if (!ids.length) return;

		pending.forEach(function (el) {
			var statusEl = el.querySelector(".ai-scoring-status");
			if (statusEl) { statusEl.className = "ai-scoring-status ai-scoring-spinner"; statusEl.textContent = "Scoring…"; }
		});

		var chunks = [];
		for (var i = 0; i < ids.length; i += CHUNK_SIZE) chunks.push(ids.slice(i, i + CHUNK_SIZE));

		function fail(chunk, message) {
			chunk.forEach(function (id) {
				var el = elMap[id];
				if (!el) return;
				el.innerHTML = '<span class="ai-score-error" title="' + escapeHtml(message || "") + '">Score failed</span>' +
					' <button class="ai-retry-score-btn">Retry</button> ' + actionButtons();
			});
		}

		function processChunk(index) {
			if (index >= chunks.length) return;
			var chunk = chunks[index];
			ajaxPost("score_batch", { entry_ids: chunk })
				.then(function (data) {
					if (data.status !== "ok" || !data.scores) { fail(chunk, data.message); }
					else {
						chunk.forEach(function (id) {
							var el = elMap[id];
							if (!el) return;
							if (data.scores[id]) renderScore(el, id, data.scores[id]);
							else if ((data.skipped || []).indexOf(id) !== -1) {
								// Feed not enabled for scoring: just leave the action buttons
								el.classList.remove("ai-score-pending");
								var s = el.querySelector(".ai-scoring-status");
								if (s) s.remove();
							} else fail([id], "not scored");
						});
					}
					processChunk(index + 1);
				})
				.catch(function () { fail(chunk, "request failed"); processChunk(index + 1); });
		}
		processChunk(0);
	}

	function renderScore(el, id, s) {
		var score = Number(s.score);
		var colorClass = score >= 7 ? "ai-score-high" : score >= 4 ? "ai-score-mid" : "ai-score-low";
		var html = '<span class="ai-score-badge ' + colorClass + '" title="' + escapeHtml(s.reason || "") + '">' + score + "</span>";
		if (s.summary) html += '<span class="ai-summary">' + escapeHtml(s.summary) + "</span>";
		if (score > 0) {
			html += '<button class="ai-summarize-btn" data-force="1" title="Write a full summary from the whole text">' + (s.summary ? "Full summary" : "Summarize") + "</button>";
			html += '<button class="ai-detail-btn">More detail</button>';
		}
		html += '<button class="ai-chat-btn">Chat</button>' +
			'<button class="ai-feedback-btn" data-dir="more" title="More like this">+</button>' +
			'<button class="ai-feedback-btn" data-dir="less" title="Less like this">&minus;</button>';
		el.innerHTML = html;
		el.classList.remove("ai-score-pending");
	}

	// ── Summarize (full) ───────────────────────────────────────────────────

	function handleSummarize(btn) {
		var container = btn.closest(".ai-assistant-container");
		var entryId = container.dataset.entryId;
		var existing = container.querySelector(".ai-summary");
		btn.disabled = true;
		btn.textContent = "Summarizing…";
		var span = null;
		var text = "";
		streamPost("summarize", { entry_id: entryId, force: btn.dataset.force === "1" },
			function onText(chunk) {
				text += chunk;
				if (!span) {
					span = existing || document.createElement("span");
					span.className = "ai-summary";
					if (!existing) btn.before(span);
				}
				span.textContent = text;
			},
			function onDone() {
				if (span) {
					btn.remove();
					if (!container.querySelector(".ai-detail-btn, .ai-detail-toggle")) {
						var d = document.createElement("button");
						d.className = "ai-detail-btn"; d.textContent = "More detail";
						span.after(d);
					}
				} else { btn.textContent = "Summarize"; btn.disabled = false; }
			},
			function onError(msg) { btn.textContent = "Failed"; btn.title = msg || ""; btn.disabled = false; }
		);
	}

	// ── Detail ─────────────────────────────────────────────────────────────

	function handleDetail(btn) {
		var container = btn.closest(".ai-assistant-container");
		var entryId = container.dataset.entryId;
		btn.disabled = true;
		btn.textContent = "Loading…";
		var detailDiv = null, fullText = "", render = null;
		streamPost("detail", { entry_id: entryId },
			function onText(text) {
				fullText += text;
				if (!detailDiv) {
					detailDiv = document.createElement("div");
					detailDiv.className = "ai-detail";
					container.appendChild(detailDiv);
					render = liveRenderer(detailDiv, true);
					btn.textContent = "Hide detail"; btn.className = "ai-detail-toggle"; btn.disabled = false;
				}
				render(fullText, false);
			},
			function onDone() { if (render) render(fullText, true); },
			function onError(msg) { btn.textContent = "Failed"; btn.title = msg || ""; btn.disabled = false; }
		);
	}

	function handleDetailToggle(btn) {
		var container = btn.closest(".ai-assistant-container");
		var detailDiv = container.querySelector(".ai-detail");
		if (!detailDiv) return;
		var hidden = detailDiv.style.display === "none";
		detailDiv.style.display = hidden ? "" : "none";
		btn.textContent = hidden ? "Hide detail" : "More detail";
	}

	// ── Feedback (+ / −) ───────────────────────────────────────────────────

	function handleFeedback(btn) {
		var container = btn.closest(".ai-assistant-container");
		var entryId = container.dataset.entryId;
		var direction = btn.dataset.dir;
		var reason = prompt(direction === "more" ? "Why should articles like this score higher?" : "Why should articles like this score lower?");
		if (reason === null) return;
		btn.disabled = true;
		btn.textContent = "…";
		ajaxPost("feedback", { entry_id: entryId, direction: direction, reason: reason })
			.then(function (data) {
				if (data.status === "ok") { btn.textContent = "✓"; btn.classList.add("confirmed"); }
				else { btn.textContent = direction === "more" ? "+" : "−"; btn.title = data.message || "Failed"; btn.disabled = false; }
			})
			.catch(function () { btn.textContent = direction === "more" ? "+" : "−"; btn.disabled = false; });
	}

	// ── Transcript / full content ──────────────────────────────────────────

	function handleLoad(btn, action, key, cls, label) {
		var entryId = btn.dataset.entryId;
		btn.disabled = true;
		btn.textContent = "Loading…";
		ajaxPost(action, { entry_id: entryId })
			.then(function (data) {
				if (data.status === "ok" && data[key]) {
					var details = document.createElement("details");
					details.className = cls + "-section";
					details.open = true;
					details.innerHTML = "<summary>" + label + "</summary><div class=\"" + cls + "-content\">" +
						escapeHtml(data[key]).replace(/\n/g, "<br>") + "</div>";
					btn.replaceWith(details);
				} else { btn.textContent = data.message || "Unavailable"; }
			})
			.catch(function () { btn.textContent = "Failed to load"; btn.disabled = false; });
	}

	// ── Chat modal ─────────────────────────────────────────────────────────

	var chatOverlay = null;

	function handleChat(btn) {
		var container = btn.closest(".ai-assistant-container");
		var entryId = container.dataset.entryId;
		var article = container.closest(".flux, .item, article, [id^='flux_']");
		var titleEl = article ? article.querySelector(".title, .item-title, h2 a, h1 a, a.title") : null;
		var title = titleEl ? titleEl.textContent.trim() : "Article";
		openChatModal(entryId, title);
	}

	function openChatModal(entryId, title) {
		if (chatOverlay) chatOverlay.remove();
		chatOverlay = document.createElement("div");
		chatOverlay.className = "ai-chat-overlay";
		chatOverlay.innerHTML =
			'<div class="ai-chat-modal">' +
			'<div class="ai-chat-header"><span class="ai-chat-title">' + escapeHtml(title) + "</span>" +
			'<div class="ai-chat-header-controls">' +
			'<select class="ai-chat-model">' +
			'<option value="">Default model</option>' +
			'<option value="claude-opus-5-5">Opus 5.5</option>' +
			'<option value="claude-sonnet-5-5">Sonnet 5.5</option>' +
			'<option value="claude-haiku-4-5">Haiku 4.5</option>' +
			"</select>" +
			'<button class="ai-chat-close">&times;</button></div></div>' +
			'<div class="ai-chat-messages"></div>' +
			'<div class="ai-chat-input-bar">' +
			'<input type="text" class="ai-chat-input" placeholder="Ask about this article…">' +
			'<button class="ai-chat-send">Send</button></div></div>';
		document.body.appendChild(chatOverlay);

		var messagesDiv = chatOverlay.querySelector(".ai-chat-messages");
		var input = chatOverlay.querySelector(".ai-chat-input");
		var sendBtn = chatOverlay.querySelector(".ai-chat-send");
		var closeBtn = chatOverlay.querySelector(".ai-chat-close");
		var modelSelect = chatOverlay.querySelector(".ai-chat-model");

		// Previous conversation about this entry, if any
		ajaxPost("chat_history", { entry_id: entryId }).then(function (data) {
			(data.messages || []).forEach(function (m) { appendMessage(messagesDiv, m.role, m.text); });
		}).catch(function () {});

		function send() {
			var text = input.value.trim();
			if (!text) return;
			appendMessage(messagesDiv, "user", text);
			input.value = "";
			sendBtn.disabled = true;
			var msgDiv = appendMessage(messagesDiv, "assistant", "");
			msgDiv.classList.add("ai-chat-thinking");
			msgDiv.textContent = "Thinking…";
			var fullText = "";
			var render = liveRenderer(msgDiv, false);
			streamPost("chat", { entry_id: entryId, message: text, model: modelSelect.value },
				function onText(chunk) {
					msgDiv.classList.remove("ai-chat-thinking");
					fullText += chunk;
					render(fullText, false);
					messagesDiv.scrollTop = messagesDiv.scrollHeight;
				},
				function onDone() {
					msgDiv.classList.remove("ai-chat-thinking");
					render(fullText || "(no response)", true);
					sendBtn.disabled = false;
					input.focus();
				},
				function onError(msg) {
					msgDiv.classList.remove("ai-chat-thinking");
					msgDiv.textContent = "Error: " + (msg || "Request failed");
					sendBtn.disabled = false;
					input.focus();
				},
				function onStatus(status) { if (!fullText) msgDiv.textContent = status; }
			);
		}

		sendBtn.addEventListener("click", send);
		input.addEventListener("keydown", function (e) { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); send(); } });
		closeBtn.addEventListener("click", closeChatModal);
		chatOverlay.addEventListener("click", function (e) { if (e.target === chatOverlay) closeChatModal(); });
		input.focus();
	}

	function appendMessage(container, role, text) {
		var div = document.createElement("div");
		div.className = "ai-chat-msg ai-chat-msg-" + role;
		div.innerHTML = role === "user" ? escapeHtml(text).replace(/\n/g, "<br>") : renderMarkdown(text, false);
		container.appendChild(div);
		container.scrollTop = container.scrollHeight;
		return div;
	}

	function closeChatModal() {
		if (chatOverlay) { chatOverlay.remove(); chatOverlay = null; }
	}

	// ── Event delegation ───────────────────────────────────────────────────

	document.addEventListener("DOMContentLoaded", function () {
		scorePendingEntries();

		// FreshRSS loads more entries as you scroll; score those too.
		var observer = new MutationObserver(function () {
			if (document.querySelector(".ai-score-pending:not(.ai-scoring-requested)")) {
				document.querySelectorAll(".ai-score-pending").forEach(function (el) { el.classList.add("ai-scoring-requested"); });
				scorePendingEntries();
			}
		});
		document.querySelectorAll(".ai-score-pending").forEach(function (el) { el.classList.add("ai-scoring-requested"); });
		var stream = document.getElementById("stream");
		if (stream) observer.observe(stream, { childList: true, subtree: false });

		document.addEventListener("click", function (e) {
			var t;
			if ((t = e.target.closest(".ai-retry-score-btn"))) {
				var c = t.closest(".ai-assistant-container");
				c.classList.add("ai-score-pending");
				c.innerHTML = '<span class="ai-scoring-status"></span>';
				scorePendingEntries();
				return;
			}
			if ((t = e.target.closest(".ai-chat-btn"))) { handleChat(t); return; }
			if ((t = e.target.closest(".ai-summarize-btn"))) { handleSummarize(t); return; }
			if ((t = e.target.closest(".ai-detail-btn"))) { handleDetail(t); return; }
			if ((t = e.target.closest(".ai-detail-toggle"))) { handleDetailToggle(t); return; }
			if ((t = e.target.closest(".ai-feedback-btn"))) { handleFeedback(t); return; }
			if ((t = e.target.closest(".ai-load-transcript-btn"))) { handleLoad(t, "fetch_transcript", "transcript", "ai-transcript", "Video transcript"); return; }
			if ((t = e.target.closest(".ai-load-fullcontent-btn"))) { handleLoad(t, "fetch_full_content", "content", "ai-fullcontent", "Full article (fetched)"); return; }
		});

		document.addEventListener("keydown", function (e) { if (e.key === "Escape" && chatOverlay) closeChatModal(); });
	});
})();
