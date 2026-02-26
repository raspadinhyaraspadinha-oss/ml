/* 

COLE ISSO NA SUA PÁGINA DE VENDAS

*/

(function () {
  const urlParams = window.location.search;

  function appendParams(url) {
    if (!url) return "";
    const separator = url.includes("?") ? "&" : "?";
    return url + separator + urlParams.slice(1);
  }

  function updateAnchorsAndButtons() {
    const anchors = document.querySelectorAll("a[href]");
    anchors.forEach((anchor) => {
      anchor.href = appendParams(anchor.href);
    });
  }

  updateAnchorsAndButtons();

  window.addEventListener("popstate", function () {
    updateAnchorsAndButtons();
  });

  const originalPushState = history.pushState;
  history.pushState = function () {
    originalPushState.apply(this, arguments);
    updateAnchorsAndButtons();
  };

  document.addEventListener("click", function (event) {
    const target = event.target.closest("a");
    if (target && target.href) {
      setTimeout(() => {
        updateAnchorsAndButtons();
      }, 100);
    }
  });
})();
