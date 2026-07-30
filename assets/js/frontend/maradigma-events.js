/**
 * MaradigmaEvents
 * Tiny event-bus based on CustomEvent to decouple modules.
 */
class MaradigmaEvents {
  static NS = "maradigma:";

  static _name(name) {
    const n = String(name || "").trim();
    if (!n) return "";
    return n.startsWith(MaradigmaEvents.NS) ? n : (MaradigmaEvents.NS + n);
  }

  static on(name, handler, options) {
    const ev = MaradigmaEvents._name(name);
    if (!ev || typeof handler !== "function") return;
    document.addEventListener(ev, handler, options);
  }

  static off(name, handler, options) {
    const ev = MaradigmaEvents._name(name);
    if (!ev || typeof handler !== "function") return;
    document.removeEventListener(ev, handler, options);
  }

  static emit(name, detail) {
    const ev = MaradigmaEvents._name(name);
    if (!ev) return;
    document.dispatchEvent(new CustomEvent(ev, { detail: detail || {} }));
  }
}

window.MaradigmaEvents = MaradigmaEvents;
