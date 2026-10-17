  <footer class="footer">
    <div class="wrap">
      <div class="f-grid">
        <div>
          <a href="/" class="logo">Livo <span>SHOP</span></a>
          <p>T-shirts simples para quem programa. Feitas em algodão, enviadas para todo o mundo.</p>
        </div>
        <div>
          <h5>Loja</h5>
          <ul>
            <li><a href="/">Todas as t-shirts</a></li>
            <li><a href="/checkout">Checkout</a></li>
          </ul>
        </div>
        <div>
          <h5>Empresa</h5>
          <ul>
            <li><a href="/about">Sobre</a></li>
            <li><a href="contacto.html">Contacto</a></li>
          </ul>
        </div>
        <div>
          <h5>Segue-nos</h5>
          <div class="social">
            <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
            <a href="#" aria-label="X"><i class="fa-brands fa-x-twitter"></i></a>
            <a href="#" aria-label="GitHub"><i class="fa-brands fa-github"></i></a>
          </div>
        </div>
      </div>
      <div class="f-bottom"><span>© <?= date('Y') ?> Livo Shop</span><span>loja ficticia em USD</span></div>
    </div>
  </footer>
  <div class="toasts" id="toasts" aria-live="polite"></div>
  <dialog class="modal" id="welcomeModal" aria-labelledby="modalTitle">
    <div class="modal-box">
      <div class="modal-icon"><i class="fa-solid fa-flask" aria-hidden="true"></i></div>

      <h2 id="modalTitle">Loja fictícia</h2>
      <p>
        Isto é só um projeto de teste, nenhuma t-shirt vai ser enviada.
        Os pagamentos são reais, por isso só paga se quiseres doar.
      </p>

      <div class="modal-btns">
        <button class="btn" type="button" data-close>
          <i class="fa-solid fa-check" aria-hidden="true"></i> Entendi
        </button>
        <a class="btn ghost" href="https://buymeacoffee.com/claudio.dev" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer">
          <i class="fa-solid fa-mug-hot" aria-hidden="true"></i> Doar um café
        </a>
      </div>

      <small class="modal-credit">
        <i class="fa-solid fa-code" aria-hidden="true"></i> Código by Cláudio Victor
      </small>
    </div>
  </dialog>
  <script src="<?= rtrim('/', '/') . '/' . 'public/assets/js/ui.js'; ?>"></script>
  <script src="<?= rtrim('/', '/') . '/' . 'public/assets/js/script.js'; ?>"></script>
</body>
</html>