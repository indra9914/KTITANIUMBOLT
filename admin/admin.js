(() => {
  const API = "/api/v1";
  const TOKEN_KEY = "ktb-admin-token";
  const USER_KEY = "ktb-admin-user";
  const $ = selector => document.querySelector(selector);
  const esc = value => String(value ?? "").replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  const money = value => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(Number(value) || 0);
  const ROLE_LABELS = { administrator: "Administrator", manager_toko: "Manager Toko", admin_toko: "Admin Toko", staff_gudang: "Staff Gudang" };
  const PERMISSION_LABELS = { "dashboard.view": "Ringkasan", "orders.view": "Lihat pesanan", "orders.manage": "Kelola pesanan", "orders.ship": "Proses pengiriman", "products.view": "Lihat produk", "products.manage_price": "Kelola harga", "inventory.manage": "Kelola stok", "payments.view": "Lihat pembayaran", "shipping.view": "Lihat pengiriman", "integrations.view": "Lihat status provider", "integrations.manage": "Ubah pengaturan provider", "users.manage": "Kelola pengguna" };
  const ROLE_DEFAULTS = {
    administrator: Object.keys(PERMISSION_LABELS),
    manager_toko: ["dashboard.view", "orders.view", "orders.manage", "orders.ship", "products.view", "products.manage_price", "inventory.manage", "payments.view", "shipping.view", "integrations.view"],
    admin_toko: ["dashboard.view", "orders.view", "orders.manage", "orders.ship", "products.view", "inventory.manage", "payments.view", "shipping.view", "integrations.view"],
    staff_gudang: ["dashboard.view", "orders.view", "orders.ship", "products.view", "inventory.manage", "shipping.view", "integrations.view"],
  };
  const roleLabel = role => ROLE_LABELS[role] || role || "Pengguna";
  const permissionFields = (permissions = []) => `<div class="user-permission-grid">${Object.entries(PERMISSION_LABELS).map(([key, label]) => `<label><input type="checkbox" value="${key}" data-permission-choice ${permissions.includes(key) ? "checked" : ""}>${esc(label)}</label>`).join("")}</div>`;
  let token = sessionStorage.getItem(TOKEN_KEY) || "";
  let currentUser = (() => { try { return JSON.parse(sessionStorage.getItem(USER_KEY) || "null"); } catch { return null; } })();
  let products = [];
  let productPage = 1;
  async function api(path, options = {}) {
    const response = await fetch(`${API}${path}`, { ...options, headers: { Authorization: `Bearer ${token}`, ...(options.body ? { "Content-Type": "application/json" } : {}), ...(options.headers || {}) } });
    const data = await response.json().catch(() => ({}));
    if (response.status === 401) { sessionStorage.removeItem(TOKEN_KEY); sessionStorage.removeItem(USER_KEY); token = ""; currentUser = null; showLogin("Token ditolak atau sesi berakhir."); }
    if (!response.ok) throw new Error(data.error || "Permintaan gagal.");
    return data;
  }
  function showLogin(text = "") {
    $(`[data-login-panel]`).classList.remove("hidden");
    $(`[data-dashboard]`).classList.add("hidden");
    $(`[data-logout]`).classList.add("hidden");
    $(`[data-admin-message]`).textContent = text;
  }
  function showDashboard() {
    $(`[data-login-panel]`).classList.add("hidden");
    $(`[data-dashboard]`).classList.remove("hidden");
    $(`[data-logout]`).classList.remove("hidden");
    const permissions = new Set(currentUser?.permissions || []);
    document.querySelectorAll(`[data-permission]`).forEach(item => item.classList.toggle("hidden", !permissions.has(item.dataset.permission)));
    $(`[data-payment-settings]`).classList.toggle("hidden", !permissions.has("integrations.manage"));
    $(`[data-shipping-settings]`).classList.toggle("hidden", !permissions.has("integrations.manage"));
    $(`[data-admin-user-name]`).textContent = `${currentUser?.display_name || "Admin"} · ${roleLabel(currentUser?.role)}`;
    const viewPermissions = { overview: "dashboard.view", orders: "orders.view", products: "products.view", payments: "payments.view", shipping: "shipping.view", users: "users.manage" };
    document.querySelectorAll(`[data-admin-view]`).forEach(view => view.classList.toggle("hidden", !permissions.has(viewPermissions[view.dataset.adminView])));
    const firstAllowedTab = document.querySelector(`[data-admin-tab]:not(.hidden)`);
    if (firstAllowedTab) {
      document.querySelectorAll(`[data-admin-tab]`).forEach(tab => tab.setAttribute("aria-pressed", String(tab === firstAllowedTab)));
      $(`[data-admin-view="${firstAllowedTab.dataset.adminTab}"]`)?.classList.remove("hidden");
    }
  }
  async function loadOrders() {
    const node = $(`[data-order-list]`);
    node.innerHTML = '<p class="message">Memuat pesanan...</p>';
    try {
      const orders = (await api("/admin/orders")).data || [];
      node.innerHTML = orders.length ? orders.map(order => {
        let transitions = { pending: ["confirmed", "cancelled"], confirmed: ["processing", "cancelled"], processing: ["shipped", "cancelled"], shipped: ["completed"], completed: [], cancelled: [] }[order.status] || [];
        if (!currentUser.permissions.includes("orders.manage")) transitions = transitions.filter(status => currentUser.permissions.includes("orders.ship") && ["processing", "shipped", "completed"].includes(status));
        return `<article class="admin-order"><div class="admin-order-head"><div><p class="admin-order-number">${esc(order.order_number)}</p><p class="admin-meta">${esc(order.created_at)} · ${esc(order.payment_method)} · pembayaran ${esc(order.payment_status || "unpaid")}</p></div><strong>${money(order.total_amount)}</strong></div><div class="admin-customer"><strong>${esc(order.customer_name)}</strong> · ${esc(order.phone)}${order.email ? ` · ${esc(order.email)}` : ""}<p>${esc(order.shipping_address)}</p><p>Pengiriman: ${esc(order.shipping_company || "-")} ${esc(order.shipping_service || "")} · ${esc(order.shipping_area_name || "Belum ada tarif")}</p></div><div class="admin-order-items">${(order.items || []).map(item => `<div>${esc(item.product_name)} · ${esc(item.sku_code)} × ${item.quantity}<strong>${money(item.subtotal)}</strong></div>`).join("")}<div><span>Ongkir</span><strong>${money(order.shipping_amount)}</strong></div></div><div class="admin-order-actions"><span class="status-tag status-${esc(order.status)}">${esc(order.status)}</span>${transitions.length ? `<select class="control status-select" aria-label="Ubah status pesanan ${esc(order.order_number)}"><option value="">Ubah status...</option>${transitions.map(status => `<option value="${status}">${status}</option>`).join("")}</select><button class="button button-small" type="button" data-order-number="${esc(order.order_number)}">Simpan</button>` : ""}</div></article>`;
      }).join("") : '<div class="empty-state">Belum ada pesanan.</div>';
      node.querySelectorAll(`[data-order-number]`).forEach(button => button.addEventListener("click", async () => {
        const select = button.previousElementSibling;
        if (!select.value) return;
        button.disabled = true;
        try { await api(`/admin/orders/${encodeURIComponent(button.dataset.orderNumber)}`, { method: "PATCH", body: JSON.stringify({ status: select.value }) }); await loadOrders(); }
        catch (error) { $(`[data-admin-global-message]`).textContent = error.message; button.disabled = false; }
      }));
    } catch (error) { node.innerHTML = `<p class="message error">${esc(error.message)}</p>`; }
  }
  async function loadSummary() {
    const node = $(`[data-admin-summary]`);
    node.innerHTML = '<p class="message">Memuat ringkasan...</p>';
    try {
      const summary = (await api("/admin/summary")).data;
      const cards = [
        ["Pesanan", summary.orders.total, `${summary.orders.pending} menunggu pembayaran`],
        ["Sudah dibayar", summary.orders.paid, `${summary.orders.fulfilling} sedang diproses/dikirim`],
        ["Pendapatan dibayar", money(summary.revenue), "Akumulasi pesanan lunas"],
        ["Produk aktif", summary.active_products, `${summary.low_stock_skus} SKU stok rendah`],
      ];
      node.innerHTML = cards.map(([label, value, detail]) => `<article class="admin-stat"><span>${esc(label)}</span><strong>${esc(value)}</strong><small>${esc(detail)}</small></article>`).join("");
    } catch (error) { node.innerHTML = `<p class="message error">${esc(error.message)}</p>`; }
  }
  async function loadIntegrations() {
    const paymentNode = $(`[data-payment-status]`);
    const shippingNode = $(`[data-shipping-status]`);
    paymentNode.innerHTML = shippingNode.innerHTML = '<p class="message">Memeriksa provider...</p>';
    try {
      const config = (await api("/admin/integrations")).data;
      paymentNode.innerHTML = `<article class="integration-card"><div><span class="integration-label">Midtrans Snap</span><span class="integration-state ${config.midtrans.enabled ? "ready" : "waiting"}">${config.midtrans.enabled ? "Aktif" : "Belum aktif"}</span></div><p>Mode ${esc(config.midtrans.environment)} · client key ${config.midtrans.client_key_configured ? "tersimpan" : "belum ada"} · server key ${config.midtrans.server_key_configured ? "tersimpan" : "belum ada"}</p></article>`;
      shippingNode.innerHTML = `<article class="integration-card"><div><span class="integration-label">Biteship · ${esc(config.biteship.warehouse_name || "Gudang")}</span><span class="integration-state ${config.biteship.enabled ? "ready" : "waiting"}">${config.biteship.enabled ? "Aktif" : "Belum aktif"}</span></div><p>API key ${config.biteship.api_key_configured ? "tersimpan" : "belum ada"} · Origin Area ID ${esc(config.biteship.origin_area_id || "belum diatur")}</p></article>`;
      const paymentForm = $(`[data-payment-settings]`);
      paymentForm.elements.midtrans_environment.value = config.midtrans.environment;
      const shippingForm = $(`[data-shipping-settings]`);
      shippingForm.elements.warehouse_name.value = config.biteship.warehouse_name || "Prepedan";
      shippingForm.elements.biteship_origin_area_id.value = config.biteship.origin_area_id || "";
      shippingForm.elements.biteship_couriers.value = config.biteship.couriers;
      shippingForm.elements.default_weight_grams.value = config.biteship.weight_grams;
      shippingForm.elements.default_package_length_cm.value = config.biteship.package_length_cm;
      shippingForm.elements.default_package_width_cm.value = config.biteship.package_width_cm;
      shippingForm.elements.default_package_height_cm.value = config.biteship.package_height_cm;
    } catch (error) {
      paymentNode.innerHTML = shippingNode.innerHTML = `<p class="message error">${esc(error.message)}</p>`;
    }
  }

  async function saveProviderSettings(form, endpoint, fields, statusNode, messageNode) {
    const submit = form.querySelector(`[type="submit"]`);
    submit.disabled = true;
    messageNode.textContent = "Menyimpan konfigurasi...";
    const values = new FormData(form);
    const payload = {};
    for (const key of fields) {
      if (key.startsWith("clear_")) payload[key] = values.has(key);
      else if (values.has(key)) payload[key] = values.get(key);
    }
    try {
      await api(endpoint, { method: "PATCH", body: JSON.stringify(payload) });
      form.querySelectorAll(`[type="password"]`).forEach(input => { input.value = ""; });
      form.querySelectorAll(`[type="checkbox"]`).forEach(input => { input.checked = false; });
      messageNode.textContent = "Pengaturan tersimpan. Secret tetap disembunyikan.";
      await loadIntegrations();
    } catch (error) {
      messageNode.textContent = error.message;
      messageNode.className = "message error";
    } finally { submit.disabled = false; }
  }
  function renderProducts(query = "") {
    const node = $(`[data-admin-products]`);
    const filtered = products.filter(product => product.name.toLowerCase().includes(query.toLowerCase()) || product.slug.includes(query.toLowerCase()));
    const pageSize = 20;
    const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
    productPage = Math.min(productPage, pageCount);
    const visible = filtered.slice((productPage - 1) * pageSize, productPage * pageSize);
    node.innerHTML = visible.length ? visible.map(product => `<article class="admin-product"><div class="admin-product-heading"><div><strong>${esc(product.name)}</strong><p class="admin-meta">${esc(product.slug)}</p></div><span>${product.skus.length} SKU</span></div><div class="admin-skus">${product.skus.filter(sku => sku.is_active).map(sku => `<form class="admin-sku-form" data-product-slug="${esc(product.slug)}" data-sku-id="${sku.id}"><span class="admin-sku-code">${esc(sku.sku_code || `SKU ${sku.id}`)}</span><label>Harga<input class="field" name="base_price" type="number" min="1" max="1000000000" value="${Number(sku.base_price)}" required></label><label>Stok<input class="field" name="stock" type="number" min="0" max="10000000" value="${Number(sku.stock || 0)}" required ${sku.is_unlimited_stock ? "disabled" : ""}></label><label class="toggle-label"><input name="is_unlimited_stock" type="checkbox" ${sku.is_unlimited_stock ? "checked" : ""}> Tanpa batas</label><button class="button button-small" type="submit">Simpan</button></form>`).join("")}</div></article>`).join("") : '<div class="empty-state">Tidak ada produk.</div>';
    const pagination = $(`[data-admin-product-pagination]`);
    pagination.classList.toggle("hidden", filtered.length <= pageSize);
    $(`[data-admin-product-page]`).textContent = `Halaman ${productPage} dari ${pageCount} · ${filtered.length} produk`;
    $(`[data-admin-product-prev]`).disabled = productPage <= 1;
    $(`[data-admin-product-next]`).disabled = productPage >= pageCount;
    const canEditPrice = currentUser?.permissions.includes("products.manage_price");
    const canEditStock = currentUser?.permissions.includes("inventory.manage");
    node.querySelectorAll(`[name="base_price"]`).forEach(input => { input.disabled = !canEditPrice; });
    node.querySelectorAll(`[name="stock"], [name="is_unlimited_stock"]`).forEach(input => { input.disabled = !canEditStock || input.disabled; });
    node.querySelectorAll(`[data-product-slug] button`).forEach(button => { button.classList.toggle("hidden", !canEditPrice && !canEditStock); });
    node.querySelectorAll(`[name="is_unlimited_stock"]`).forEach(toggle => toggle.addEventListener("change", () => { const stock = toggle.form.elements.stock; stock.disabled = toggle.checked; if (toggle.checked) stock.value = "0"; }));
    node.querySelectorAll(`[data-product-slug]`).forEach(form => form.addEventListener("submit", async event => {
      event.preventDefault();
      const values = new FormData(form);
      const update = { sku_id: Number(form.dataset.skuId) };
      if (currentUser.permissions.includes("products.manage_price")) update.base_price = Number(values.get("base_price"));
      if (currentUser.permissions.includes("inventory.manage")) {
        update.is_unlimited_stock = values.has("is_unlimited_stock");
        if (!update.is_unlimited_stock) update.stock = Number(values.get("stock"));
      }
      const button = form.querySelector("button"); button.disabled = true;
      try { await api(`/admin/products/${encodeURIComponent(form.dataset.productSlug)}`, { method: "PATCH", body: JSON.stringify({ sku_updates: [update] }) }); await loadProducts(); $(`[data-admin-global-message]`).textContent = "SKU diperbarui."; }
      catch (error) { $(`[data-admin-global-message]`).textContent = error.message; button.disabled = false; }
    }));
  }
  async function loadProducts() {
    const node = $(`[data-admin-products]`); node.innerHTML = '<p class="message">Memuat produk...</p>';
    try { products = (await api("/products?per_page=100&sort=name_asc")).data || []; productPage = 1; renderProducts($(`[data-admin-product-search]`).value); }
    catch (error) { node.innerHTML = `<p class="message error">${esc(error.message)}</p>`; }
  }
  function attachPermissionControls(root, roleSelect) {
    roleSelect.addEventListener("change", () => {
      const container = root.querySelector(`[data-user-permission-fields]`);
      container.innerHTML = permissionFields(ROLE_DEFAULTS[roleSelect.value] || []);
    });
  }
  function checkedPermissions(root) {
    return [...root.querySelectorAll(`[data-permission-choice]:checked`)].map(input => input.value);
  }
  async function loadUsers() {
    const node = $(`[data-user-list]`);
    node.innerHTML = '<p class="message">Memuat pengguna...</p>';
    try {
      const users = (await api("/admin/users")).data || [];
      const roles = ["administrator", "manager_toko", "admin_toko", "staff_gudang"];
      node.innerHTML = users.length ? users.map(user => `<article class="admin-user"><form data-edit-user="${esc(user.id)}"><div class="admin-user-heading"><div><strong>${esc(user.display_name)}</strong><p class="admin-meta">@${esc(user.username)} · login terakhir ${esc(user.last_login_at || "belum pernah")}</p></div><span class="status-tag ${user.is_active ? "status-confirmed" : "status-cancelled"}">${user.is_active ? "Aktif" : "Nonaktif"}</span></div><div class="user-edit-grid"><label>Nama tampil<input class="field" name="display_name" value="${esc(user.display_name)}" required maxlength="100"></label><label>Role<select class="field" name="role">${roles.map(role => `<option value="${role}" ${role === user.role ? "selected" : ""}>${roleLabel(role)}</option>`).join("")}</select></label><label>Password baru (opsional)<input class="field" name="password" type="password" minlength="12" autocomplete="new-password" placeholder="Kosongkan untuk tidak mengubah"></label><label class="toggle-label"><input name="is_active" type="checkbox" ${user.is_active ? "checked" : ""}> Akun aktif</label></div><fieldset class="permission-picker"><legend>Hak akses</legend><div data-user-permission-fields>${permissionFields(user.permissions)}</div></fieldset><button class="button button-small" type="submit">Simpan perubahan</button><p class="message" data-user-message></p></form></article>`).join("") : '<div class="empty-state">Belum ada akun staf. Buat akun pertama di atas.</div>';
      node.querySelectorAll(`[data-edit-user]`).forEach(form => {
        attachPermissionControls(form, form.elements.role);
        form.addEventListener("submit", async event => {
          event.preventDefault();
          const values = new FormData(form), body = { display_name: values.get("display_name"), role: values.get("role"), is_active: values.has("is_active"), permissions: checkedPermissions(form) };
          if (values.get("password")) body.password = values.get("password");
          const button = form.querySelector(`[type="submit"]`), note = form.querySelector(`[data-user-message]`);
          button.disabled = true; note.textContent = "Menyimpan...";
          try { await api(`/admin/users/${encodeURIComponent(form.dataset.editUser)}`, { method: "PATCH", body: JSON.stringify(body) }); note.textContent = "Hak akses diperbarui."; await loadUsers(); }
          catch (error) { note.textContent = error.message; note.className = "message error"; button.disabled = false; }
        });
      });
    } catch (error) { node.innerHTML = `<p class="message error">${esc(error.message)}</p>`; }
  }
  async function enter(tokenValue) {
    token = tokenValue;
    currentUser = (await api("/admin/me")).data;
    sessionStorage.setItem(TOKEN_KEY, token);
    sessionStorage.setItem(USER_KEY, JSON.stringify(currentUser));
    showDashboard();
    if (currentUser.permissions.includes("dashboard.view")) loadSummary();
    if (currentUser.permissions.includes("orders.view")) loadOrders();
    if (currentUser.permissions.includes("products.view")) loadProducts();
    if (currentUser.permissions.includes("integrations.view")) loadIntegrations();
    if (currentUser.permissions.includes("users.manage")) loadUsers();
  }
  document.addEventListener("DOMContentLoaded", () => {
    $(`[data-login-form]`).addEventListener("submit", async event => {
      event.preventDefault();
      const button = event.currentTarget.querySelector("button"); button.disabled = true;
      const adminToken = $(`[data-admin-token]`).value.trim();
      const username = $(`[data-admin-username]`).value.trim();
      const password = $(`[data-admin-password]`).value;
      try {
        if (!adminToken && (!username || !password)) throw new Error("Masukkan admin token atau username dan password.");
        const response = await fetch(`${API}/auth/login`, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(adminToken ? { admin_token: adminToken } : { username, password }) });
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.error || "Login gagal.");
        await enter(result.data.token);
        $(`[data-admin-password]`).value = "";
      } catch (error) { token = ""; currentUser = null; sessionStorage.removeItem(TOKEN_KEY); sessionStorage.removeItem(USER_KEY); showLogin(error.message); }
      button.disabled = false;
    });
    const createForm = $(`[data-user-create]`);
    const createRole = createForm.elements.role;
    createForm.querySelector(`[data-create-permissions]`).innerHTML = permissionFields(ROLE_DEFAULTS[createRole.value]);
    createRole.addEventListener("change", () => { createForm.querySelector(`[data-create-permissions]`).innerHTML = permissionFields(ROLE_DEFAULTS[createRole.value] || []); });
    createForm.addEventListener("submit", async event => {
      event.preventDefault();
      const values = new FormData(createForm), note = createForm.querySelector(`[data-user-create-message]`), button = createForm.querySelector(`[type="submit"]`);
      const body = { display_name: values.get("display_name"), username: values.get("username"), password: values.get("password"), role: values.get("role"), permissions: checkedPermissions(createForm) };
      button.disabled = true; note.textContent = "Membuat akun...";
      try { await api("/admin/users", { method: "POST", body: JSON.stringify(body) }); createForm.reset(); createRole.value = "manager_toko"; createForm.querySelector(`[data-create-permissions]`).innerHTML = permissionFields(ROLE_DEFAULTS.manager_toko); note.textContent = "Akun berhasil dibuat."; await loadUsers(); }
      catch (error) { note.textContent = error.message; note.className = "message error"; }
      finally { button.disabled = false; }
    });
    $(`[data-logout]`).addEventListener("click", async () => { try { await api("/auth/logout", { method: "POST" }); } catch { } sessionStorage.removeItem(TOKEN_KEY); sessionStorage.removeItem(USER_KEY); token = ""; currentUser = null; showLogin(); });
    $(`[data-refresh-orders]`).addEventListener("click", loadOrders);
    $(`[data-refresh-summary]`).addEventListener("click", loadSummary);
    $(`[data-refresh-users]`).addEventListener("click", loadUsers);
    document.querySelectorAll(`[data-refresh-integrations]`).forEach(button => button.addEventListener("click", loadIntegrations));
    $(`[data-payment-settings]`).addEventListener("submit", event => {
      event.preventDefault();
      saveProviderSettings(event.currentTarget, "/admin/integrations", ["midtrans_environment", "midtrans_client_key", "midtrans_server_key", "clear_midtrans"], $(`[data-payment-status]`), $(`[data-payment-message]`));
    });
    $(`[data-shipping-settings]`).addEventListener("submit", event => {
      event.preventDefault();
      saveProviderSettings(event.currentTarget, "/admin/integrations", ["warehouse_name", "biteship_api_key", "biteship_origin_area_id", "biteship_couriers", "default_weight_grams", "default_package_length_cm", "default_package_width_cm", "default_package_height_cm", "clear_biteship"], $(`[data-shipping-status]`), $(`[data-shipping-message]`));
    });
    $(`[data-admin-product-search]`).addEventListener("input", event => { productPage = 1; renderProducts(event.target.value); });
    $(`[data-admin-product-prev]`).addEventListener("click", () => { productPage--; renderProducts($(`[data-admin-product-search]`).value); });
    $(`[data-admin-product-next]`).addEventListener("click", () => { productPage++; renderProducts($(`[data-admin-product-search]`).value); });
    document.querySelectorAll(`[data-admin-tab]`).forEach(button => button.addEventListener("click", () => {
      document.querySelectorAll(`[data-admin-tab]`).forEach(tab => tab.setAttribute("aria-pressed", String(tab === button)));
      document.querySelectorAll(`[data-admin-view]`).forEach(view => view.classList.toggle("hidden", view.dataset.adminView !== button.dataset.adminTab));
      if (button.dataset.adminTab === "overview") loadSummary();
      if (button.dataset.adminTab === "orders") loadOrders();
      if (button.dataset.adminTab === "products") loadProducts();
      if (button.dataset.adminTab === "payments" || button.dataset.adminTab === "shipping") loadIntegrations();
      if (button.dataset.adminTab === "users") loadUsers();
    }));
    if (token) enter(token).catch(error => showLogin(error.message)); else showLogin();
  });
})();
