// Cloudflare Worker for edge Markdown negotiation on the storefront homepage.
// Serves a Markdown representation when an AI agent requests Accept: text/markdown.
export default {
  async fetch(request, env, ctx) {
    const accept = request.headers.get("accept") || "";
    const url = new URL(request.url);

    // Match substring instead of strict q-value comparison because some agent
    // scanners send mixed Accept headers containing both text/html and text/markdown.
    if (accept.toLowerCase().includes("text/markdown") && (url.pathname === "/" || url.pathname === "/index.php")) {
      const originUrl = new URL("/render-markdown", request.url);
      const originRes = await fetch(originUrl, {
        headers: {
          "User-Agent": request.headers.get("user-agent") || "Cloudflare-Worker"
        }
      });

      const body = await originRes.text();
      return new Response(body, {
        status: 200,
        headers: {
          "Content-Type": "text/markdown; charset=utf-8",
          "Vary": "Accept",
          "Cache-Control": "no-cache, no-store, must-revalidate"
        }
      });
    }

    return fetch(request);
  }
};
