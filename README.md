<p align="center">
  <img src="public/assets/img/favicon.svg" alt="Livo Shop logo" width="96" height="96">
</p>

<h1 align="center">Livo Shop</h1>

<p align="center">
  A fictional t-shirt store for developers.<br>
  Node, Laravel, Go, PHP and more.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-1a1a1a?style=for-the-badge&logo=php&logoColor=b8d93c" alt="PHP">
  <img src="https://img.shields.io/badge/JavaScript-1a1a1a?style=for-the-badge&logo=javascript&logoColor=b8d93c" alt="JavaScript">
  <img src="https://img.shields.io/badge/HTML5-1a1a1a?style=for-the-badge&logo=html5&logoColor=b8d93c" alt="HTML5">
  <img src="https://img.shields.io/badge/CSS3-1a1a1a?style=for-the-badge&logo=css3&logoColor=b8d93c" alt="CSS3">
  <img src="https://img.shields.io/badge/Composer-1a1a1a?style=for-the-badge&logo=composer&logoColor=b8d93c" alt="Composer">
</p>

<p align="center">
  <a href="https://buymeacoffee.com/claudio.dev">
    <img src="https://img.shields.io/badge/Buy%20me%20a%20coffee-b8d93c?style=for-the-badge&logo=buymeacoffee&logoColor=1a1a1a" alt="Buy me a coffee">
  </a>
</p>

---

## About

Livo Shop is a small storefront built to test one question: how simple can it be to put an online payment to work?

The store has a hero section, a product grid, a slide-in cart, search, and static pages for About and Contact. Checkout is handled by [Buy Me a Coffee](https://buymeacoffee.com/claudio.dev).

> **Heads up:** this is a test project. The products are fictional and no t-shirt will ever be shipped. Payments, however, are real, so only pay if you actually want to donate.

## Features

- Responsive layout, mobile first
- Product grid rendered from a JavaScript data array
- Slide-in cart (CSS-only open/close) with quantity controls and stock limits
- Cart persisted in `localStorage`
- Live search and hamburger menu
- Toast notifications
- Welcome modal explaining the project
- Custom 404 page
- Prices formatted in Angolan kwanza (AOA)
- No gradients, no frameworks, no build step

## Tech stack

| Layer     | Tools                                    |
| --------- | ---------------------------------------- |
| Backend   | PHP, Composer                            |
| Frontend  | HTML, CSS, vanilla JavaScript            |
| Icons     | Font Awesome                             |
| Font      | Space Grotesk (Google Fonts)             |
| Payments  | Buy Me a Coffee                          |

## Getting started

**Requirements:** PHP 8 or newer and [Composer](https://getcomposer.org/).

```bash
# 1. Clone the repository
git clone git remote add origin https://github.com/claudiovictors/livo-shop.git
cd livo-shop

# 2. Install dependencies
composer install

# 3. Start the local server
php -S localhost:8000
```

Then open <http://localhost:8000> in your browser.

## Customizing products

Products live in a plain array at the top of `public/assets/js/script.js`:

```js
{
  id: 4,
  slug: "go",
  name: "Go",
  description: "Navy t-shirt with the Go gopher.",
  price: 12000,
  stock: 12,
  image: "/public/assets/img/home-tee-04.jpg",
}
```

Change the name, description, price or stock, drop a new image into `public/assets/img/`, and reload the page.

## Security notes

- The cart stores only product ids and quantities. Names, prices and stock are always read from the products array.
- Never trust prices sent from the browser. When you wire up a real payment flow, recalculate the total on the server.
- External links use `rel="noopener noreferrer"`.

## Author

Code by **Cláudio Victor**.

If you enjoyed the project, you can [buy me a coffee](https://buymeacoffee.com/claudio.dev).

## License

Add a `LICENSE` file to define how others can use this project (for example, MIT).