<?php

declare(strict_types=1);

/**
 * "Assistant" page inside FreshRSS: embeds the assistant UI (chat, briefs,
 * reader, settings) and signs the user in automatically.
 */
final class FreshExtension_assistant_Controller extends Minz_ActionController {

	#[\Override]
	public function firstAction(): void {
		if (!FreshRSS_Auth::hasAccess()) {
			Minz_Error::error(403);
		}
	}

	public function indexAction(): void {
		FreshRSS_View::prependTitle('Assistant · ');
		$view = Minz_Request::paramString('view', true);
		$ext = Minz_ExtensionManager::findExtension('AI Assistant');
		$this->view->assistantUrl = $ext instanceof AiAssistantExtension ? $ext->ssoUrl($view) : '';
		$this->view->publicUrl = $ext instanceof AiAssistantExtension ? $ext->publicUrlForView($view) : '';

		// FreshRSS defaults to frame-src 'self'; allow the assistant's origin for the embedded UI.
		$origin = "'self'";
		if ($this->view->assistantUrl !== '') {
			$parts = parse_url($this->view->assistantUrl);
			if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
				$origin .= ' ' . $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
			}
		}
		$this->_csp([
			'default-src' => "'self'",
			'frame-src' => $origin,
			'frame-ancestors' => "'none'",
		]);
	}
}
