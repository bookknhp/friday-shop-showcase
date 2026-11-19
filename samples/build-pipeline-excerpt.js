// Sanitized architecture excerpt: Build pipeline pattern
// Demonstrates content-based MD5 build caching and automatic bundle splitting
// (Storefront critical bundle, Deferred lazy pages, and Admin bundle).

const fs = require("fs");
const path = require("path");
const crypto = require("crypto");

const BASE = path.join(__dirname, "../");

function getFilesHash(filePaths, extraString = "") {
  const hash = crypto.createHash("md5");
  if (extraString) hash.update(extraString);

  for (const f of filePaths) {
    const fullPath = path.isAbsolute(f) ? f : path.join(BASE, f);
    if (fs.existsSync(fullPath)) {
      const relPath = path.relative(BASE, fullPath).replace(/\\/g, "/");
      // Content-based MD5 survives git clone, zip extract, and mtime changes
      hash.update(`${relPath}\0`);
      hash.update(fs.readFileSync(fullPath));
      hash.update("\0");
    }
  }
  return hash.digest("hex");
}

function getAllFilesByExt(dirPath, exts = [".js", ".jsx"], acc = []) {
  if (!fs.existsSync(dirPath)) return acc;
  for (const file of fs.readdirSync(dirPath)) {
    const fullPath = path.join(dirPath, file);
    if (fs.statSync(fullPath).isDirectory()) {
      getAllFilesByExt(fullPath, exts, acc);
    } else if (exts.some((ext) => file.endsWith(ext))) {
      acc.push(fullPath.replace(/\\/g, "/"));
    }
  }
  return acc;
}

const DEFERRED_PAGES = [
  "CheckoutPage.js",
  "ProductDetailPage.js",
  "OrderHistoryPage.js",
  "AccountManagementPage.js",
  "SearchResultsPage.js",
  "ChatWidget.js",
];

function discoverBundleGroups(rootDir) {
  const allComponents = getAllFilesByExt(path.join(rootDir, "components"));
  const allPages = getAllFilesByExt(path.join(rootDir, "pages"));
  const allUtils = getAllFilesByExt(path.join(rootDir, "utils"));

  const adminFiles = [...allComponents, ...allPages].filter((f) => f.includes("/admin/"));
  const storefrontAll = [...allUtils, ...allComponents, ...allPages].filter(
    (f) => !f.includes("/admin/") && !f.endsWith("app.js"),
  );

  const deferredFiles = storefrontAll.filter((f) =>
    DEFERRED_PAGES.some((name) => f.endsWith(name)),
  );
  const criticalFiles = storefrontAll.filter(
    (f) => !DEFERRED_PAGES.some((name) => f.endsWith(name)),
  );

  return { criticalFiles, deferredFiles, adminFiles };
}

module.exports = { getFilesHash, discoverBundleGroups };
