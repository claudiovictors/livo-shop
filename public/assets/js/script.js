const products = [
  { id: 1, slug: "php", name: "PHP", description: "T-shirt preta com o logo PHP.", price: 8500, stock: 20, image: "/public/assets/img/home-tee-01.jpg" },
  { id: 2, slug: "laravel", name: "Laravel", description: "T-shirt branca com o logo Laravel.", price: 9500, stock: 15, image: "/public/assets/img/home-tee-02.jpg" },
  { id: 3, slug: "nodejs", name: "Node.js", description: "T-shirt cinza com o hexágono do Node.", price: 9000, stock: 18, image: "/public/assets/img/home-tee-03.jpg" },
  { id: 4, slug: "go", name: "Go", description: "T-shirt azul-marinho com o mascote do Go.", price: 12000, stock: 12, image: "/public/assets/img/home-tee-04.jpg" },
  { id: 5, slug: "javascript", name: "JavaScript", description: "T-shirt preta com o logo JS amarelo.", price: 8000, stock: 30, image: "/public/assets/img/home-tee-05.jpg" },
  { id: 6, slug: "slenix", name: "Slenix", description: "T-shirt branca com o elefante Slonik.", price: 10000, stock: 10, image: "/public/assets/img/home-tee-06.jpg" },
  { id: 7, slug: "terminal", name: "Terminal", description: "T-shirt escura com o prompt >_ no peito.", price: 8500, stock: 25, image: "/public/assets/img/home-tee-07.jpg" },
  { id: 8, slug: "peak", name: "Peak", description: "T-shirt preta com ícone de montanha.", price: 11000, stock: 8, image: "/public/assets/img/home-tee-08.jpg" },
];

const productGrid =document.querySelector(".grid");
const cartItems =document.querySelector(".items");
const btnNext =document.querySelector("#btn-checkout");
const totalAmount =document.querySelector("#total");
const cartCounter =document.querySelector(".numberTotal");
const toasts =document.querySelector("#toasts");

const format = new Intl.NumberFormat("pt-AO", {
  style: "currency",
  currency: "AOA",
  maximumFractionDigits: 0,
});

const getCart = () => JSON.parse(localStorage.getItem("cart")) || [];

const saveCart = (cart) => {
  localStorage.setItem("cart", JSON.stringify(cart));
  renderCart();
};

const toastIcons = {
  success: "fa-circle-check",
  error: "fa-circle-exclamation",
  info: "fa-circle-info",
};

function showToast(message, type = "success") {
  if (!toasts) return;

  while (toasts.children.length >= 3) toasts.firstElementChild.remove();

  const toast = document.createElement("div");
  toast.className = `toast ${type}`;
  toast.setAttribute("role", type === "error" ? "alert" : "status");

  const icon = document.createElement("i");
  icon.className = `fa-solid ${toastIcons[type]}`;

  const text = document.createElement("span");
  text.textContent = message;

  const close = document.createElement("button");
  close.type = "button";
  close.setAttribute("aria-label", "Fechar");
  close.innerHTML = "&times;";

  toast.append(icon, text, close);
  toasts.append(toast);

  const dismiss = () => {
    toast.classList.add("hide");
    setTimeout(() => toast.remove(), 200);
  };

  close.addEventListener("click", dismiss);
  setTimeout(dismiss, 3000);
}

function renderProducts() {
  if (!productGrid) return;

  productGrid.innerHTML = products
    .map(
      (product) => `
        <article class="card" data-name="${product.name}">
            <img src="${product.image}" alt="${product.name}" loading="lazy">
            <h3>${product.name}</h3>
            <p>${product.description}</p>
            <footer>
                <b>${format.format(product.price)}</b>
                <button onclick="addProduct(${product.id})" class="add" type="button">Adicionar</button>
            </footer>
        </article>`,
    )
    .join("");
}

function addProduct(productId) {
  const product = products.find((p) => p.id === productId);
  if (!product) return;

  const cart = getCart();
  const item = cart.find((i) => i.id === productId);

  if (item && item.quantity >= product.stock) {
    showToast(`Stock máximo de ${product.name} atingido`, "error");
    return;
  }

  if (item) {
    item.quantity += 1;
  } else {
    cart.push({ ...product, quantity: 1 });
  }

  saveCart(cart);
  showToast(`${product.name} adicionado ao carrinho`);
}

function changeQuantity(productId, delta) {
  const cart = getCart();
  const item = cart.find((i) => i.id === productId);
  if (!item) return;

  const product = products.find((p) => p.id === productId);

  if (delta > 0 && item.quantity >= product.stock) {
    showToast(`Stock máximo de ${product.name} atingido`, "error");
    return;
  }

  item.quantity += delta;

  if (item.quantity <= 0) {
    saveCart(cart.filter((i) => i.id !== productId));
    showToast(`${product.name} removido do carrinho`, "info");
    return;
  }

  saveCart(cart);
}

function renderCart() {
  const cart = getCart();

  if (cartCounter) {
    cartCounter.textContent = cart.reduce((sum, item) => sum + item.quantity, 0);
  }

  if (!cartItems) return;

  if (cart.length === 0) {
    cartItems.innerHTML = `
        <div class="cart-empty">
            <i class="fa-solid fa-bag-shopping"></i>
            <h3>O teu carrinho está vazio</h3>
            <p>Escolhe uma t-shirt e ela aparece aqui.</p>
            <label for="cart-toggle" class="btn">Ver t-shirts</label>
        </div>`;
    totalAmount.textContent = format.format(0);
    btnNext?.classList.add("is-disabled");
    return;
  }

  cartItems.innerHTML = cart
    .map(
      (item) => `
        <div class="item">
            <img src="${item.image}" alt="${item.name}">
            <div>
                <h4>${item.name}</h4>
                <small>${format.format(item.price)} cada</small>
                <div class="qty">
                    <button type="button" aria-label="Diminuir" onclick="changeQuantity(${item.id}, -1)">-</button>
                    <span>${item.quantity}</span>
                    <button type="button" aria-label="Aumentar" onclick="changeQuantity(${item.id}, 1)">+</button>
                </div>
            </div>
            <b>${format.format(item.price * item.quantity)}</b>
        </div>`,
    )
    .join("");

  const total = cart.reduce((sum, item) => sum + item.price * item.quantity, 0);
  totalAmount.textContent = format.format(total);
  btnNext?.classList.remove("is-disabled");
}

const welcomeModal = document.querySelector("#welcomeModal");

function closeModal() {
  if (!welcomeModal?.open) return;
  welcomeModal.classList.add("closing");
  setTimeout(() => {
    welcomeModal.close();
    welcomeModal.classList.remove("closing");
  }, 250);
}

if (welcomeModal) {
  let seen = false;
  try {
    seen = sessionStorage.getItem("modalSeen") === "1";
  } catch (e) {}

  if (!seen) {
    setTimeout(() => welcomeModal.showModal(), 600);
    try {
      sessionStorage.setItem("modalSeen", "1");
    } catch (e) {}
  }

  welcomeModal.querySelectorAll("[data-close]").forEach((btn) => {
    btn.addEventListener("click", closeModal);
  });

  welcomeModal.addEventListener("click", (e) => {
    if (e.target === welcomeModal) closeModal();
  });

  welcomeModal.addEventListener("cancel", (e) => {
    e.preventDefault();
    closeModal();
  });
}

renderProducts();
renderCart();