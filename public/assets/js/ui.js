// Apenas menu hamburguer e pesquisa.
const $ = (id) => document.getElementById(id);

if ($("burger")) {
  $("burger").onclick = () => {
    const open = $("nav").classList.toggle("open");
    $("burger").innerHTML = `<i class="fa-solid ${open ? "fa-xmark" : "fa-bars"}"></i>`;
  };
  $("nav").onclick = () => $("nav").classList.remove("open");
}

if ($("searchBtn")) {
  $("searchBtn").onclick = () => {
    const open = $("search").classList.toggle("open");
    if (open) $("searchInput").focus();
  };
  $("searchInput").oninput = (e) => {
    const term = e.target.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll("#grid .card").forEach((c) => {
      const show = c.dataset.name.toLowerCase().includes(term);
      c.style.display = show ? "" : "none";
      if (show) visible++;
    });
    $("empty").style.display = visible ? "none" : "block";
  };
}