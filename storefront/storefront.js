(() => {
  const API = "/api/v1";
  const CART_KEY = "ktb-cart-v1";
  const $ = (selector, root = document) => root.querySelector(selector);
  const money = value => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(Number(value) || 0);
  const esc = value => String(value ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const cart = () => { try { return JSON.parse(localStorage.getItem(CART_KEY) || "[]"); } catch { return []; } };
  const saveCart = value => { localStorage.setItem(CART_KEY, JSON.stringify(value)); syncCount(); window.dispatchEvent(new Event("cart-updated")); };
  const syncCount = () => document.querySelectorAll("[data-cart-count]").forEach(node => node.textContent = cart().reduce((n, item) => n + item.quantity, 0));
  async function api(path, options = {}) {
    const response = await fetch(`${API}${path}`, { ...options, headers: { ...(options.body ? { "Content-Type": "application/json" } : {}), ...(options.headers || {}) } });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw Object.assign(new Error(data.error || "Permintaan gagal."), { data, status: response.status });
    return data;
  }
  const category = product => {
    const text = `${product.name} ${product.slug}`.toLowerCase();
    if (text.includes("emblem")) return "Emblem";
    if (text.includes("mur") || text.includes("open-nut")) return "Mur";
    if (text.includes("baut")) return "Baut";
    if (text.includes("heng") || text.includes("stainless")) return "HENG & Stainless";
    return "Aksesoris";
  };
  const card = product => `<article class="product-card"><a href="/katalog/${encodeURIComponent(product.slug)}"><div class="product-image"><img src="${esc(product.primary_image || "/images/logo.jpeg")}" alt="${esc(product.name)}" loading="lazy"></div><div class="product-body"><p class="product-category">${category(product)}</p><h2 class="product-name">${esc(product.name)}</h2><p class="product-price">${money(product.min_discount_price || product.min_price)}</p><p class="product-stock">${product.has_unlimited_sku || Number(product.total_stock) > 0 ? "Siap dipesan" : "Stok perlu dikonfirmasi"}</p></div></a></article>`;
  const message = (node, text, kind = "") => { if (node) { node.textContent = text; node.className = `message${kind ? ` ${kind}` : ""}`; } };
  const providerMessage = error => error.data?.error === "shipping_not_configured"
    ? "Ongkir otomatis belum aktif. Admin perlu mengisi Biteship API key dan origin area ID."
    : error.data?.error === "payment_not_configured"
      ? "Pembayaran otomatis belum aktif. Admin perlu mengisi kredensial Midtrans."
      : error.message;

  async function catalog() {
    const grid = $(`[data-products]`), search = $(`[data-search]`), sort = $(`[data-sort]`), summary = $(`[data-catalog-summary]`);
    const previous = $(`[data-page-prev]`), next = $(`[data-page-next]`), pageLabel = $(`[data-page-number]`);
    const chips = [...document.querySelectorAll(`[data-category]`)];
    let page = 1, selected = "Semua", sequence = 0, timer;
    async function load() {
      const id = ++sequence;
      grid.innerHTML = '<div class="empty-state">Memuat katalog...</div>';
      try {
        const perPage = selected === "Semua" ? 24 : 100;
        const params = new URLSearchParams({ per_page: String(perPage), page: String(selected === "Semua" ? page : 1), sort: sort.value });
        if (search.value.trim()) params.set("q", search.value.trim());
        const result = await api(`/products?${params}`);
        if (id !== sequence) return;
        const matching = selected === "Semua" ? result.data : result.data.filter(p => category(p) === selected);
        const pageCount = selected === "Semua" ? result.meta.last_page : Math.max(1, Math.ceil(matching.length / 24));
        const products = selected === "Semua" ? matching : matching.slice((page - 1) * 24, page * 24);
        grid.innerHTML = products.length ? products.map(card).join("") : '<div class="empty-state">Tidak ada produk pada pencarian ini.</div>';
        summary.textContent = `${(selected === "Semua" ? result.meta.total : matching.length).toLocaleString("id-ID")} produk tersedia`;
        pageLabel.textContent = `Halaman ${page} dari ${pageCount}`;
        previous.disabled = page <= 1; next.disabled = page >= pageCount;
      } catch (error) { grid.innerHTML = `<div class="empty-state">Katalog gagal dimuat: ${esc(error.message)}</div>`; }
    }
    search.addEventListener("input", () => { clearTimeout(timer); page = 1; timer = setTimeout(load, 220); });
    sort.addEventListener("change", () => { page = 1; load(); });
    chips.forEach(chip => chip.addEventListener("click", () => { selected = chip.dataset.category; chips.forEach(c => c.setAttribute("aria-pressed", String(c === chip))); page = 1; load(); }));
    previous.addEventListener("click", () => { if (page > 1) { page--; load(); window.scrollTo({ top: 240, behavior: "smooth" }); } });
    next.addEventListener("click", () => { page++; load(); window.scrollTo({ top: 240, behavior: "smooth" }); });
    load();
  }

  async function productDetail() {
    const note = $(`[data-product-message]`);
    const slug = new URLSearchParams(location.search).get("slug") || location.pathname.split("/").filter(Boolean).pop()?.replace(/\.html$/, "");
    try {
      const product = (await api(`/products/${encodeURIComponent(slug)}`)).data;
      const image = $(`[data-product-image]`), selector = $(`[data-sku]`), quantity = $(`[data-quantity]`), add = $(`[data-add-cart]`);
      image.src = product.primary_image || "/images/logo.jpeg"; image.alt = product.name;
      $(`[data-product-name]`).textContent = product.name;
      $(`[data-product-description]`).textContent = `${product.name}. Komponen titanium dan stainless untuk motor. Pilih SKU sebelum menambahkan produk ke keranjang.`;
      $(`[data-product-stock]`).textContent = product.has_unlimited_sku ? "Stok tersedia" : `${Number(product.total_stock || 0).toLocaleString("id-ID")} stok tercatat`;
      document.title = `${product.name} | K-Titanium Bolt`;
      const skus = (product.skus || []).filter(s => s.is_active);
      selector.innerHTML = skus.map(s => `<option value="${s.id}" data-price="${Number(s.base_price)}" data-min="${Number(s.min_order_qty || 1)}" data-stock="${Number(s.stock || 0)}" data-unlimited="${Boolean(s.is_unlimited_stock)}">${esc(s.sku_code || `SKU ${s.id}`)} · ${money(s.base_price)}${s.is_unlimited_stock ? "" : ` · stok ${s.stock}`}</option>`).join("");
      const updateSku = () => { const option = selector.selectedOptions[0]; quantity.min = option?.dataset.min || "1"; quantity.value = quantity.min; $(`[data-product-price]`).textContent = money(option?.dataset.price); add.disabled = !option || (option.dataset.unlimited === "false" && Number(option.dataset.stock) < Number(option.dataset.min)); };
      selector.addEventListener("change", updateSku); updateSku();
      add.addEventListener("click", () => {
        const option = selector.selectedOptions[0], count = Number(quantity.value);
        if (!option || !Number.isInteger(count) || count < Number(option.dataset.min)) return message(note, "Jumlah belum memenuhi minimum pembelian.", "error");
        if (option.dataset.unlimited === "false" && count > Number(option.dataset.stock)) return message(note, "Jumlah melebihi stok tersedia.", "error");
        const current = cart(), existing = current.find(item => item.product_id === product.id && item.sku_id === Number(option.value));
        if (existing) existing.quantity += count;
        else current.push({ product_id: product.id, sku_id: Number(option.value), slug: product.slug, name: product.name, sku_code: option.textContent.split(" · ")[0], price: Number(option.dataset.price), quantity: count, image: product.primary_image, minimum: Number(option.dataset.min), unlimited: option.dataset.unlimited === "true", stock: Number(option.dataset.stock) });
        saveCart(current); message(note, "Ditambahkan ke keranjang.", "success");
      });
    } catch (error) { message(note, `Produk gagal dimuat: ${error.message}`, "error"); }
  }

  function checkout() {
    const cartNode = $(`[data-cart-items]`), summary = $(`[data-order-summary]`), areaInput = $(`[data-area-query]`);
    const areasNode = $(`[data-area-results]`), ratesNode = $(`[data-shipping-rates]`), note = $(`[data-checkout-message]`), submit = $(`[data-submit-order]`);
    let destination = null, rate = null, timer;
    function renderCart() {
      const items = cart();
      submit.disabled = !items.length;
      cartNode.innerHTML = items.length ? items.map((item, i) => `<div class="cart-row"><img src="${esc(item.image || "/images/logo.jpeg")}" alt=""><div><h3>${esc(item.name)}</h3><p>${esc(item.sku_code)} · ${money(item.price)}</p><input class="field" type="number" min="${item.minimum || 1}" max="${item.unlimited ? 99 : item.stock}" value="${item.quantity}" data-quantity-index="${i}" aria-label="Jumlah"></div><button class="remove-item" type="button" data-remove-index="${i}">Hapus</button></div>`).join("") : '<p class="message">Keranjang kosong. <a href="/katalog">Pilih produk</a>.</p>';
      cartNode.querySelectorAll(`[data-quantity-index]`).forEach(input => input.addEventListener("change", () => { const updated = cart(), item = updated[Number(input.dataset.quantityIndex)], count = Number(input.value); if (!item || !Number.isInteger(count) || count < item.minimum || (!item.unlimited && count > item.stock)) return renderCart(); item.quantity = count; saveCart(updated); renderCart(); }));
      cartNode.querySelectorAll(`[data-remove-index]`).forEach(button => button.addEventListener("click", () => { const updated = cart(); updated.splice(Number(button.dataset.removeIndex), 1); saveCart(updated); renderCart(); }));
      const subtotal = items.reduce((sum, item) => sum + item.price * item.quantity, 0), shipping = rate ? Number(rate.price) : 0;
      summary.innerHTML = `${items.map(item => `<div class="summary-line"><span>${esc(item.name)}<small>${item.quantity} × ${money(item.price)}</small></span><strong>${money(item.quantity * item.price)}</strong></div>`).join("")}<div class="summary-line"><span>Ongkir${rate ? `<small>${esc(rate.company)} ${esc(rate.service)}</small>` : ""}</span><strong>${rate ? money(shipping) : "Pilih tarif"}</strong></div><div class="summary-total"><span>Total</span><span>${money(subtotal + shipping)}</span></div>`;
    }
    renderCart(); window.addEventListener("cart-updated", renderCart);
    areaInput.addEventListener("input", () => {
      clearTimeout(timer); destination = null; rate = null; ratesNode.innerHTML = ""; renderCart();
      if (areaInput.value.trim().length < 3) { areasNode.innerHTML = ""; return; }
      areasNode.innerHTML = '<p class="message">Mencari area...</p>';
      timer = setTimeout(async () => {
        try {
          const areas = (await api(`/shipping/areas?q=${encodeURIComponent(areaInput.value.trim())}`)).data || [];
          areasNode.innerHTML = areas.length ? areas.map((a, i) => `<button class="area-option" type="button" data-area-index="${i}">${esc(a.name)}${a.postal_code ? ` · ${esc(a.postal_code)}` : ""}</button>`).join("") : '<p class="message">Area tidak ditemukan. Cari nama kecamatan atau kode pos.</p>';
          areasNode.querySelectorAll(`[data-area-index]`).forEach(button => button.addEventListener("click", () => { destination = areas[Number(button.dataset.areaIndex)]; areaInput.value = destination.name; areasNode.innerHTML = `<p class="message success">Tujuan: ${esc(destination.name)}</p>`; loadRates(); }));
        } catch (error) { areasNode.innerHTML = `<p class="message error">${esc(providerMessage(error))}</p>`; }
      }, 280);
    });
    async function loadRates() {
      ratesNode.innerHTML = '<p class="message">Menghitung tarif...</p>';
      try {
        const data = await api("/shipping/rates", { method: "POST", body: JSON.stringify({ destination_area_id: destination.id, items: cart().map(item => ({ product_id: item.product_id, sku_id: item.sku_id, quantity: item.quantity })) }) });
        const options = data.data || [];
        ratesNode.innerHTML = options.length ? options.map((r, i) => `<button class="rate-option" type="button" data-rate-index="${i}" aria-pressed="false"><span><strong>${esc(r.company)} · ${esc(r.service)}</strong><small>Estimasi ${esc(r.duration || "kurir")}</small></span><strong>${money(r.price)}</strong></button>`).join("") : '<p class="message">Tidak ada tarif untuk area ini.</p>';
        ratesNode.querySelectorAll(`[data-rate-index]`).forEach(button => button.addEventListener("click", () => { rate = options[Number(button.dataset.rateIndex)]; ratesNode.querySelectorAll(`[data-rate-index]`).forEach(b => b.setAttribute("aria-pressed", String(b === button))); renderCart(); }));
      } catch (error) { ratesNode.innerHTML = `<p class="message error">${esc(providerMessage(error))}</p>`; }
    }
    $(`[data-checkout-form]`).addEventListener("submit", async event => {
      event.preventDefault();
      if (!cart().length || !destination || !rate) return message(note, "Lengkapi alamat dan pilih tarif pengiriman.", "error");
      submit.disabled = true; message(note, "Membuat pesanan dan menyiapkan pembayaran...");
      const form = new FormData(event.currentTarget);
      try {
        const result = (await api("/orders", { method: "POST", body: JSON.stringify({ customer_name: form.get("customer_name"), phone: form.get("phone"), email: form.get("email"), shipping_address: form.get("shipping_address"), notes: form.get("notes"), payment_method: "midtrans", destination_area_id: destination.id, destination_area_name: destination.name, courier_company: rate.company_code, courier_service_code: rate.service_code, items: cart().map(item => ({ product_id: item.product_id, sku_id: item.sku_id, quantity: item.quantity })) }) })).data;
        localStorage.setItem("ktb-last-order", JSON.stringify(result));
        if (result.payment_token && window.snap) window.snap.pay(result.payment_token, { onSuccess: () => location.href = `/pesanan?order=${encodeURIComponent(result.order_number)}&paid=1`, onPending: () => location.href = `/pesanan?order=${encodeURIComponent(result.order_number)}`, onError: () => { message(note, "Pembayaran belum selesai. Pesanan tersimpan.", "error"); submit.disabled = false; }, onClose: () => location.href = `/pesanan?order=${encodeURIComponent(result.order_number)}` });
        else if (result.confirmation_url) location.href = result.confirmation_url;
        else location.href = `/pesanan?order=${encodeURIComponent(result.order_number)}`;
      } catch (error) { message(note, providerMessage(error), "error"); submit.disabled = false; }
    });
    api("/config").then(config => {
      const setup = [];
      if (!config.shipping?.enabled) setup.push("Biteship (API key dan origin area ID)");
      if (!config.midtrans?.enabled || !config.midtrans.client_key) setup.push("Midtrans (client key dan server key)");
      if (setup.length) return message(note, `Checkout otomatis menunggu konfigurasi: ${setup.join("; ")}.`);
      const script = document.createElement("script"); script.src = `${config.midtrans.snap_url}?client_key=${encodeURIComponent(config.midtrans.client_key)}`; script.onload = () => { document.documentElement.dataset.paymentReady = "true"; }; script.onerror = () => message(note, "Payment gateway gagal dimuat.", "error"); document.head.append(script);
    }).catch(() => message(note, "Status pembayaran belum tersedia."));
  }

  function orderPage() {
    let detail = {}; try { detail = JSON.parse(localStorage.getItem("ktb-last-order") || "{}"); } catch { }
    const number = new URLSearchParams(location.search).get("order") || detail.order_number || "-";
    $(`[data-order-number]`).textContent = number;
    $(`[data-order-status]`).textContent = "Memeriksa status pembayaran...";
    $(`[data-order-total]`).textContent = money(detail.total_amount);
    if (number !== "-") api(`/orders/${encodeURIComponent(number)}`).then(result => {
      $(`[data-order-status]`).textContent = result.data.payment_status === "paid" ? "Pembayaran diterima" : result.data.payment_status === "paid_review" ? "Pembayaran diterima, menunggu konfirmasi admin" : result.data.payment_status === "expire" ? "Pembayaran kedaluwarsa" : result.data.status === "cancelled" ? "Pesanan dibatalkan" : "Menunggu pembayaran";
      $(`[data-order-total]`).textContent = money(result.data.total_amount);
    }).catch(() => { $(`[data-order-status]`).textContent = "Pesanan diterima; status pembayaran belum tersedia."; });
    $(`[data-order-continue]`).addEventListener("click", () => { localStorage.removeItem(CART_KEY); localStorage.removeItem("ktb-last-order"); });
  }

  document.addEventListener("DOMContentLoaded", () => {
    syncCount();
    const view = document.body.dataset.view;
    if (view === "catalog") catalog();
    if (view === "product") productDetail();
    if (view === "checkout") checkout();
    if (view === "order") orderPage();
  });
})();
