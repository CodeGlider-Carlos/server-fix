/*
ez/pats/js/pats_ui.js
*/
(() => {
  "use strict";

  document.addEventListener("DOMContentLoaded", () => {
    const btnGuide = document.getElementById("btnPatsGuideToggle");
    const guideBody = document.getElementById("patsGuideBody");

    if (btnGuide && guideBody) {
      btnGuide.addEventListener("click", () => {
        const isHidden = guideBody.hasAttribute("hidden");
        if (isHidden) {
          guideBody.removeAttribute("hidden");
          btnGuide.setAttribute("aria-expanded", "true");
          btnGuide.querySelector("span").textContent = "Ocultar guía";
        } else {
          guideBody.setAttribute("hidden", "hidden");
          btnGuide.setAttribute("aria-expanded", "false");
          btnGuide.querySelector("span").textContent = "Mostrar guía";
        }
      });
    }
  });
})();