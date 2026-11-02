/* Concursei — JavaScript progressivo. O site funciona sem JS; aqui só melhorias.
 * Regras: nunca usar innerHTML com dados do servidor/IA; usar textContent. */
(function () {
  'use strict';

  // Menu móvel acessível.
  var toggle = document.querySelector('[data-nav-toggle]');
  var nav = document.getElementById('menu-principal');
  if (toggle && nav) {
    toggle.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }

  // Confirmação para formulários destrutivos: <form data-confirm="Mensagem">.
  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (form instanceof HTMLFormElement && form.dataset.confirm) {
      if (!window.confirm(form.dataset.confirm)) {
        ev.preventDefault();
      }
    }
  });

  // Botão de impressão: <button data-print>.
  document.addEventListener('click', function (ev) {
    var el = ev.target instanceof Element ? ev.target.closest('[data-print]') : null;
    if (el) {
      window.print();
    }
  });

  // Exposto para módulos: lê o token CSRF da meta/input para requisições fetch.
  window.Concursei = window.Concursei || {};
  window.Concursei.csrf = function () {
    var input = document.querySelector('input[name="_csrf"]');
    return input ? input.value : '';
  };
})();
