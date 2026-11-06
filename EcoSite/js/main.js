document.addEventListener('DOMContentLoaded', function () {
  var navbar = document.querySelector('.navbar');
  var toggle = document.querySelector('.navbar-toggle');
  var menu = document.querySelector('.navbar-menu');

  // ----- Menu mobile -----
  function setMenu(open) {
    if (!toggle || !menu) return;
    toggle.classList.toggle('active', open);
    menu.classList.toggle('active', open);
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    onScroll();
  }

  if (toggle && menu) {
    toggle.addEventListener('click', function () {
      setMenu(!menu.classList.contains('active'));
    });

    menu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', function () { setMenu(false); });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') setMenu(false);
    });
  }

  // ----- Navbar: fundo sólido depois de rolar -----
  function onScroll() {
    var menuOpen = menu && menu.classList.contains('active');
    if (navbar) navbar.classList.toggle('scrolled', window.scrollY > 40 || menuOpen);
  }
  onScroll();
  window.addEventListener('scroll', onScroll, { passive: true });

  // ----- Link ativo no menu (blog / estágios) -----
  var path = window.location.pathname.replace(/\/index\.html$/, '/');
  document.querySelectorAll('.navbar-menu a[data-section]').forEach(function (link) {
    var section = link.getAttribute('data-section');
    if (section && path.indexOf('/' + section + '/') === 0) {
      link.classList.add('is-active');
      link.setAttribute('aria-current', 'page');
    }
  });

  // ----- Animações de entrada -----
  var faders = document.querySelectorAll('.fade-in');
  if (faders.length > 0) {
    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

      faders.forEach(function (el) { observer.observe(el); });
    } else {
      faders.forEach(function (el) { el.classList.add('visible'); });
    }
  }

  // ----- Filtros do blog -----
  var filterBtns = document.querySelectorAll('.blog-filter-btn');
  var blogCards = document.querySelectorAll('.blog-card[data-category]');

  filterBtns.forEach(function (btn) {
    btn.addEventListener('click', function () {
      filterBtns.forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');

      var category = btn.getAttribute('data-filter');
      blogCards.forEach(function (card) {
        var show = category === 'todos' || card.getAttribute('data-category') === category;
        card.style.display = show ? '' : 'none';
      });
    });
  });

  // ----- Formulário de contato (abre o e-mail pré-preenchido) -----
  var contatoForm = document.querySelector('.contato-form[data-email]');

  if (contatoForm) {
    contatoForm.addEventListener('submit', function (event) {
      event.preventDefault();

      var emailTo = contatoForm.getAttribute('data-email');
      var nome = contatoForm.querySelector('[name="nome"]').value.trim();
      var email = contatoForm.querySelector('[name="email"]').value.trim();
      var mensagem = contatoForm.querySelector('[name="mensagem"]').value.trim();

      var subject = 'Contato pelo site';
      var body = [
        'Olá, vim pelo site da Ecotec.',
        '',
        'Nome: ' + nome,
        'E-mail: ' + email,
        'Mensagem: ' + mensagem
      ].join('\n');

      window.location.href = 'mailto:' + emailTo +
        '?subject=' + encodeURIComponent(subject) +
        '&body=' + encodeURIComponent(body);
    });
  }
});
