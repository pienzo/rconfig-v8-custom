// Monaco 0.56+ exposes worker entries under its package exports map.
// Vite must bundle these as real worker entry points, not resolve the
// internal default blob/import URLs at runtime. No CDN or network fallback.
import EditorWorker from "monaco-editor/editor/editor.worker.js?worker";
import JsonWorker from "monaco-editor/languages/features/json/json.worker.js?worker";
import CssWorker from "monaco-editor/languages/features/css/css.worker.js?worker";
import HtmlWorker from "monaco-editor/languages/features/html/html.worker.js?worker";
import TsWorker from "monaco-editor/languages/features/typescript/ts.worker.js?worker";

globalThis.MonacoEnvironment = {
    ...globalThis.MonacoEnvironment,
    getWorker(_workerId, label) {
        switch (label) {
            case "json":
                return new JsonWorker();
            case "css":
            case "scss":
            case "less":
                return new CssWorker();
            case "html":
            case "handlebars":
            case "razor":
                return new HtmlWorker();
            case "typescript":
            case "javascript":
                return new TsWorker();
            default:
                return new EditorWorker();
        }
    },
};
