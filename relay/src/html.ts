/**
 * The two HTML pages the relay serves: the consent interstitial and a generic
 * error page. No scripts, no external assets, nothing reflected unescaped.
 */

export function escapeHtml(value: string): string {
	return value
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}

const STYLE = `
:root { color-scheme: light; }
* { box-sizing: border-box; }
body { margin: 0; font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f3f5f8; color: #1d2327; }
main { max-width: 520px; margin: 8vh auto; padding: 32px; background: #fff; border: 1px solid #dcdfe4; border-radius: 12px; }
h1 { font-size: 22px; margin: 0 0 12px; }
p { margin: 0 0 16px; }
code { word-break: break-all; }
.site { font-weight: 600; word-break: break-all; }
.actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 24px; }
button, a.button { display: inline-block; padding: 10px 18px; border-radius: 8px; border: 1px solid #1d4ed8; background: #1d4ed8; color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
a.secondary { border-color: #dcdfe4; background: #fff; color: #1d2327; }
footer { margin-top: 28px; font-size: 13px; color: #646970; }
`;

function page(title: string, body: string): string {
	return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>${escapeHtml(title)}</title>
<style>${STYLE}</style>
</head>
<body>
<main>
${body}
</main>
</body>
</html>
`;
}

export interface InterstitialInput {
	siteUrl: string;
	adminUrl: string;
	operator: string;
	fields: Record<string, string>;
	scopeSummary: string[];
}

export function interstitialPage(input: InterstitialInput): string {
	const hidden = Object.entries(input.fields)
		.map(([name, value]) => `<input type="hidden" name="${escapeHtml(name)}" value="${escapeHtml(value)}">`)
		.join('\n');
	const scopes = input.scopeSummary.map((line) => `<li>${escapeHtml(line)}</li>`).join('');
	return page(
		'Connect Search Console',
		`<h1>Connect Search Console to <span class="site">${escapeHtml(input.siteUrl)}</span>?</h1>
<p>You will be taken to Google to choose an account and allow access. Google then sends the connection straight back to that site. ${escapeHtml(
			input.operator,
		)} never sees your Google password and keeps no copy of the connection.</p>
<p>The site will be able to:</p>
<ul>${scopes}</ul>
<form method="post" action="/v1/authorize" class="actions">
${hidden}
<button type="submit">Continue to Google</button>
<a class="button secondary" href="${escapeHtml(input.adminUrl)}">Cancel</a>
</form>
<footer>Only continue if you opened this page from the Convertrack plugin on that site.</footer>`,
	);
}

export function errorPage(title: string, message: string, code: string): string {
	return page(
		title,
		`<h1>${escapeHtml(title)}</h1>
<p>${escapeHtml(message)}</p>
<p>Go back to your WordPress site, open <strong>Convertrack &rarr; Search &amp; SEO &rarr; Indexing</strong> and click <strong>Sign in with Google</strong> again.</p>
<footer>Error code: <code>${escapeHtml(code)}</code></footer>`,
	);
}
