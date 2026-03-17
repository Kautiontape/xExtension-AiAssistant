"use strict";

(function () {
	var EXT_NAME = "AiAssistant";

	function getAjaxUrl(action) {
		var url = new URL(window.location.pathname, window.location.origin);
		url.searchParams.set("c", "extension");
		url.searchParams.set("a", "configure");
		url.searchParams.set("e", EXT_NAME);
		url.searchParams.set("ajax_action", action);
		return url.toString();
	}

	function getCsrfToken() {
		var meta = document.getElementById("ai-assistant-meta");
		return meta ? meta.dataset.token : "";
	}

	// ── Batch scoring ────────────────────────────────────────────────────────

	function scorePendingEntries() {
		var pending = document.querySelectorAll(".ai-score-pending");
		if (!pending.length) return;

		var ids = [];
		pending.forEach(function (el) {
			var id = el.dataset.entryId;
			if (id) ids.push(id);
		});
		if (!ids.length) return;

		// Show loading state
		pending.forEach(function (el) {
			el.innerHTML = '<span class="ai-scoring-spinner">Scoring\u2026</span>';
		});

		fetch(getAjaxUrl("score_batch"), {
			method: "POST",
			headers: { "Content-Type": "application/x-www-form-urlencoded" },
			body: new URLSearchParams({
				entry_ids: JSON.stringify(ids),
				_csrf: getCsrfToken(),
			}),
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.status !== "ok" || !data.scores) {
					console.error("AI scoring failed:", data.message);
					pending.forEach(function (el) {
						el.innerHTML = '<span class="ai-score-error">Score failed</span>'
							+ ' <button class="ai-retry-score-btn">Retry</button>';
					});
					return;
				}

				var scoreMap = {};
				data.scores.forEach(function (s) {
					scoreMap[s.id] = s;
				});

				pending.forEach(function (el) {
					var id = el.dataset.entryId;
					var s = scoreMap[id];
					if (!s) {
						el.innerHTML = "";
						return;
					}

					var colorClass = s.score >= 7 ? "ai-score-high"
						: s.score >= 4 ? "ai-score-mid" : "ai-score-low";

					var html = '<span class="ai-score-badge ' + colorClass
						+ '" title="' + (s.reason || "").replace(/"/g, "&quot;") + '">'
						+ s.score + "</span>";

					if (s.summary) {
						html += '<span class="ai-summary">'
							+ s.summary.replace(/</g, "&lt;").replace(/>/g, "&gt;")
							+ "</span>";
					} else {
						html += '<button class="ai-summarize-btn">Summarize</button>';
					}

					html += '<button class="ai-feedback-btn" data-dir="more" title="More like this">+</button>'
						+ '<button class="ai-feedback-btn" data-dir="less" title="Less like this">&minus;</button>';

					el.innerHTML = html;
					el.classList.remove("ai-score-pending");
				});
			})
			.catch(function (err) {
				console.error("AI scoring request failed:", err);
				pending.forEach(function (el) {
					el.innerHTML = '<span class="ai-score-error">Score failed</span>'
						+ ' <button class="ai-retry-score-btn">Retry</button>';
				});
			});
	}

	// ── Summarize ────────────────────────────────────────────────────────────

	function handleSummarize(btn) {
		var container = btn.closest(".ai-assistant-container");
		var entryId = container.dataset.entryId;

		btn.disabled = true;
		btn.textContent = "Summarizing\u2026";

		fetch(getAjaxUrl("summarize"), {
			method: "POST",
			headers: { "Content-Type": "application/x-www-form-urlencoded" },
			body: new URLSearchParams({
				entry_id: entryId,
				_csrf: getCsrfToken(),
			}),
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.status === "ok" && data.summary) {
					var span = document.createElement("span");
					span.className = "ai-summary";
					span.textContent = data.summary;
					btn.replaceWith(span);
				} else {
					btn.textContent = "Failed";
					btn.disabled = false;
				}
			})
			.catch(function () {
				btn.textContent = "Failed";
				btn.disabled = false;
			});
	}

	// ── Feedback ─────────────────────────────────────────────────────────────

	function handleFeedback(btn) {
		var container = btn.closest(".ai-assistant-container");
		var entryId = container.dataset.entryId;
		var direction = btn.dataset.dir;

		var reason = prompt(
			direction === "more"
				? "Why should articles like this score higher?"
				: "Why should articles like this score lower?"
		);
		if (reason === null) return;

		btn.disabled = true;

		fetch(getAjaxUrl("feedback"), {
			method: "POST",
			headers: { "Content-Type": "application/x-www-form-urlencoded" },
			body: new URLSearchParams({
				entry_id: entryId,
				direction: direction,
				reason: reason,
				_csrf: getCsrfToken(),
			}),
		})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.status === "ok") {
					btn.textContent = "\u2713";
					btn.classList.add("confirmed");
				} else {
					btn.disabled = false;
				}
			})
			.catch(function () {
				btn.disabled = false;
			});
	}

	// ── Event delegation ─────────────────────────────────────────────────────

	document.addEventListener("DOMContentLoaded", function () {
		scorePendingEntries();

		document.addEventListener("click", function (e) {
			var retryBtn = e.target.closest(".ai-retry-score-btn");
			if (retryBtn) {
				var container = retryBtn.closest(".ai-assistant-container");
				container.classList.add("ai-score-pending");
				container.innerHTML = "";
				scorePendingEntries();
				return;
			}

			var summarizeBtn = e.target.closest(".ai-summarize-btn");
			if (summarizeBtn) {
				handleSummarize(summarizeBtn);
				return;
			}

			var feedbackBtn = e.target.closest(".ai-feedback-btn");
			if (feedbackBtn) {
				handleFeedback(feedbackBtn);
				return;
			}
		});
	});
})();
