// Sanitized architecture excerpt: In-browser WebMCP tool registration pattern
// Registered safely in a static, CSP-nonced script with structured error responses
// and prompt-injection hints.

(function() {
  function registerWebMCPExample() {
    if (window.__webmcp_registered) {
      return true;
    }
    const modelContext =
      (typeof document !== "undefined" && document.modelContext) ||
      (typeof navigator !== "undefined" && navigator.modelContext) ||
      (typeof window !== "undefined" && window.modelContext);

    if (!modelContext) {
      return false;
    }

    const apiBaseUrl = window.API_BASE_URL || "/api";
    const origin = window.location.origin;

    const requestJson = async function(action, params, options) {
      const url = new URL(apiBaseUrl, origin);
      url.searchParams.set("action", action);

      // Keep POST payloads out of query strings so sensitive cart/address data
      // never leaks into server access logs.
      const isPost = options && String(options.method || "").toUpperCase() === "POST";
      Object.entries(isPost ? {} : (params || {})).forEach(function([key, value]) {
        if (value === undefined || value === null || value === "") return;
        if (Array.isArray(value)) {
          value.forEach(function(item) {
            url.searchParams.append(key, String(item));
          });
          return;
        }
        url.searchParams.set(key, String(value));
      });

      const requestOptions = options || {};
      const headers = {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest"
      };

      if (!(requestOptions.body instanceof FormData)) {
        headers["Content-Type"] = "application/json";
      }

      const response = await fetch(url.toString(), {
        ...requestOptions,
        headers: { ...headers, ...(requestOptions.headers || {}) }
      });

      const data = await response.json().catch(function() {
        return null;
      });

      if (!response.ok) {
        const errorMessage = data && data.error ? data.error : ("Request failed with HTTP " + response.status);
        const error = new Error(errorMessage);
        error.status = response.status;
        throw error;
      }

      return data;
    };

    // Return structured errors instead of throwing raw exceptions so in-browser
    // agents can recover cleanly rather than receiving an opaque DOMException.
    const invalidInput = function(message) {
      return { success: false, error: message, code: "INVALID_INPUT", retryable: false };
    };

    // Flag third-party catalog text with untrustedContentHint to guard against
    // prompt injection embedded in external merchant descriptions.
    const productAnnotations = { readOnlyHint: true, untrustedContentHint: true };

    const searchProductsTool = {
      name: "search_products",
      title: "Search Products",
      description: "Find small-pet products by keyword, pet type, health benefit, life stage, or shadow category.",
      inputSchema: {
        type: "object",
        properties: {
          query: { type: "string", description: "Keywords such as product name, brand, or SKU" },
          limit: { type: "integer", minimum: 1, maximum: 10, default: 3 },
          page: { type: "integer", minimum: 1, default: 1 },
          pet_type: { type: "string", enum: ["กระต่าย", "แกสบี้", "ชินชิลล่า", "แฮมสเตอร์", "ชูการ์ไกลเดอร์", "เม่นแคระ", "แพรรีด็อก", "เฟอเรท"] },
          benefit: { type: "string" },
          life_stage: { type: "string", enum: ["เด็ก", "โต", "สูงวัย", "ทุกวัย"] },
          granular_category: { type: "string" }
        },
        additionalProperties: false
      },
      annotations: productAnnotations,
      execute: async function(params, options) {
        const query = String((params && params.query) || "").toLowerCase().trim();
        if (!query && !(params && params.pet_type) && !(params && params.benefit)) {
          return invalidInput("Provide at least one search filter (query, pet_type, or benefit).");
        }
        return requestJson("search_products", {
          q: query,
          per_page: Math.min(Math.max(Number.parseInt(params && params.limit, 10) || 3, 1), 10),
          page: Math.max(Number.parseInt(params && params.page, 10) || 1, 1),
          view: "agent"
        }, { signal: options && options.signal });
      }
    };

    if (typeof modelContext.registerTool === "function") {
      modelContext.registerTool(searchProductsTool);
      window.__webmcp_registered = true;
    }
    return true;
  }

  registerWebMCPExample();
})();
