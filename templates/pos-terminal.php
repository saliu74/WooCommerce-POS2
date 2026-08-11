<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user = wp_get_current_user();
$store_name = get_bloginfo( 'name' );
$receipt_header = get_option( 'wc_pos_receipt_header', 'Thank you for shopping with us!' );
$receipt_footer = get_option( 'wc_pos_receipt_footer', 'Please keep receipt for returns within 30 days.' );
$rest_nonce = wp_create_nonce( 'wp_rest' );
$rest_url = esc_url_raw( rest_url( 'wc-pos/v1' ) );
$currency_symbol = function_exists('get_woocommerce_currency_symbol') ? html_entity_decode( get_woocommerce_currency_symbol() ) : '₦';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?> class="dark">
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?php echo esc_html( $store_name ); ?> - POS Terminal Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: { 500: '#6366f1', 600: '#4f46e5' }
                    }
                }
            }
        }
    </script>
    <style>
        @media print {
            /* Hide everything on the page */
            body > *                        { display: none !important; }
            /* Show only the receipt */
            body > #printable-receipt       { display: block !important; }
            #printable-receipt, #printable-receipt * { visibility: visible !important; }
            #printable-receipt { position: static; width: 100%; margin: 0; padding: 16px; }
        }
        /* Reliable Light / Dark Mode Overrides */
        html.light body { background-color: #f1f5f9 !important; color: #0f172a !important; }
        html.light header { background-color: #ffffff !important; border-color: #cbd5e1 !important; }
        html.light aside { background-color: #ffffff !important; border-color: #cbd5e1 !important; }
        /* Responsive fix: hide the scrollbar on the header's horizontally-
           scrollable control cluster (small screens) — Tailwind's CDN build
           has no scrollbar-hide utility by default. */
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        /* Bug fix: the category pills bar was also using scrollbar-hide,
           which removed the only visual cue that there were more categories
           to scroll to — cashiers had no way to tell "All Products" wasn't
           the whole list. A thin, subtle (but visible) scrollbar instead. */
        .scrollbar-thin::-webkit-scrollbar { height: 6px; }
        .scrollbar-thin::-webkit-scrollbar-track { background: transparent; }
        .scrollbar-thin::-webkit-scrollbar-thumb { background: #475569; border-radius: 3px; }
        .scrollbar-thin { scrollbar-width: thin; scrollbar-color: #475569 transparent; }
        html.light main { background-color: #f8fafc !important; border-color: #cbd5e1 !important; }
        html.light .pos-card { background-color: #ffffff !important; border-color: #cbd5e1 !important; color: #0f172a !important; }
        html.light .pos-card h3 { color: #0f172a !important; }
        html.light .pos-card .bg-slate-900 { background-color: #f1f5f9 !important; }
        html.light .bg-slate-950 { background-color: #ffffff !important; border-color: #cbd5e1 !important; }
        html.light .bg-slate-900 { background-color: #f8fafc !important; border-color: #cbd5e1 !important; }
        html.light .bg-slate-800 { background-color: #ffffff !important; border-color: #cbd5e1 !important; color: #0f172a !important; }
        html.light .text-white { color: #0f172a !important; }
        html.light .text-slate-300 { color: #1e293b !important; }
        html.light .text-slate-400 { color: #64748b !important; }
        html.light input { background-color: #ffffff !important; color: #0f172a !important; border-color: #cbd5e1 !important; }
    </style>
</head>
<body class="bg-slate-900 text-slate-100 font-sans h-screen flex flex-col overflow-hidden select-none transition-colors duration-200">

    <!-- POS Top Navigation Bar -->
    <header class="bg-slate-950 border-b border-slate-800 px-4 py-2.5 flex items-center justify-between shrink-0 z-10">
        <div class="flex items-center space-x-3">
            <button onclick="toggleSidebar()" class="p-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition lg:hidden">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <div class="w-3 h-3 rounded-full bg-emerald-500 animate-pulse"></div>
            <div>
                <h1 class="text-xs font-bold text-white uppercase tracking-wider"><?php echo esc_html( $store_name ); ?> POS</h1>
                <p class="text-[10px] text-slate-400">Cashier: <strong class="text-slate-200"><?php echo esc_html( $user->display_name ); ?></strong></p>
            </div>
        </div>

        <div class="flex items-center space-x-2 overflow-x-auto max-w-[60vw] sm:max-w-none scrollbar-hide">
            <span id="pos-sync-status" class="hidden sm:inline-flex shrink-0 text-[11px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-2.5 py-0.5 rounded-full font-mono">
                &bull; SYNCED
            </span>

            <!-- Branch / Register picker (multi-branch feature) -->
            <button onclick="openBranchPicker()" id="branch-register-indicator" class="shrink-0 text-[11px] bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-300 border border-indigo-500/30 px-2.5 py-1 rounded-lg font-mono transition" title="Change Branch / Register">
                &#127970; <span id="branch-register-label">Select Branch</span>
            </button>

            <!-- Shift open/close indicator -->
            <button onclick="openShiftModal()" id="shift-indicator" class="hidden shrink-0 text-[11px] px-2.5 py-1 rounded-lg font-mono transition border" title="Open / Close Register Shift">
                <span id="shift-indicator-label">Shift: —</span>
            </button>

            <!-- Dark / Light Theme Toggle -->
            <button onclick="toggleTheme()" class="shrink-0 p-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-amber-400 transition" title="Toggle Theme">
                <svg id="theme-icon-sun" class="w-4 h-4 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                <svg id="theme-icon-moon" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
            </button>

            <!-- Change PIN -->
            <button onclick="openChangePinModal()" class="shrink-0 p-2 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 transition" title="Change My PIN">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 11-12 0 6 6 0 0112 0zM12 15v6m-3-3h6"></path></svg>
            </button>

            <!-- Lock Terminal -->
            <button onclick="lockTerminal()" class="shrink-0 p-2 rounded-lg bg-amber-600/20 hover:bg-amber-600/30 text-amber-300 border border-amber-500/30 transition text-xs flex items-center space-x-1" title="Lock Register">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                <span class="hidden sm:inline">Lock</span>
            </button>

            <!-- Exit Terminal -->
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-pos-pro' ) ); ?>" class="shrink-0 text-xs bg-slate-800 hover:bg-slate-700 text-slate-300 px-3 py-1.5 rounded-lg border border-slate-700 transition">
                Exit
            </a>
        </div>
    </header>

    <!-- Main Workspace -->
    <div class="flex-1 flex overflow-hidden">

        <!-- Mobile-only backdrop for the sidebar overlay -->
        <div id="pos-sidebar-backdrop" onclick="toggleSidebar()" class="hidden fixed inset-0 bg-black/50 z-20 lg:hidden"></div>

        <!-- Sidebar Navigation (Complete matched menu) -->
        <!-- Responsive fix: this was always visible at a fixed 64px width,
             which — combined with the cart's fixed 384px width — made the
             layout unusable below ~1024px (a phone screen is often narrower
             than the cart panel alone). Hidden by default, shown via lg:flex
             on desktop/tablet, and toggleable as a fixed overlay on mobile
             via the existing hamburger button + toggleSidebar(). -->
        <aside id="pos-sidebar" class="hidden lg:flex fixed lg:static inset-y-0 left-0 z-30 w-16 bg-slate-950 border-r border-slate-800 flex-col items-center py-4 space-y-5 shrink-0">
            <button onclick="switchTab('register')" id="nav-btn-register" class="p-3 rounded-xl bg-indigo-600 text-white shadow-lg transition" title="POS Terminal / Register">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            </button>
            <button onclick="switchTab('history')" id="nav-btn-history" class="p-3 rounded-xl hover:bg-slate-800 text-slate-400 hover:text-white transition" title="Order Receipts & History">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
            </button>
            <button onclick="switchTab('parked')" id="nav-btn-parked" class="p-3 rounded-xl hover:bg-slate-800 text-slate-400 hover:text-white transition relative" title="Parked Carts">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span id="parked-count-badge" class="hidden absolute top-1 right-1 bg-amber-500 text-black font-extrabold text-[9px] w-4 h-4 rounded-full flex items-center justify-center">0</span>
            </button>
            <button onclick="openCustomerModal()" id="nav-btn-customers" class="p-3 rounded-xl hover:bg-slate-800 text-slate-400 hover:text-white transition" title="Customers Directory">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            </button>
            <button onclick="lockTerminal()" class="p-3 rounded-xl hover:bg-slate-800 text-slate-400 hover:text-white transition mt-auto" title="Lock Register">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </button>
        </aside>

        <!-- VIEW 1: Main Register Grid & Cart -->
        <div id="view-register" class="flex-1 flex overflow-hidden">
            <!-- Left: Product Catalog -->
            <main class="flex-1 flex flex-col p-4 space-y-3 overflow-hidden border-r border-slate-800">
                <div class="flex items-center space-x-2">
                    <div class="relative flex-1">
                        <input type="text" id="product-search" oninput="onProductSearchInput()" placeholder="Search product name, SKU, or scan barcode..." class="w-full bg-slate-800 border border-slate-700 rounded-xl pl-10 pr-4 py-2.5 text-xs text-white placeholder-slate-400 focus:outline-none focus:border-indigo-500" autofocus />
                        <svg class="w-4 h-4 absolute left-3.5 top-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </div>
                    <button onclick="fetchProducts()" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition flex items-center space-x-1.5 shrink-0">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        <span>Refresh</span>
                    </button>
                </div>

                <!-- Product Categories Filter Pills Bar -->
                <div id="category-pills-bar" class="flex items-center space-x-2 overflow-x-auto pb-1 shrink-0 scrollbar-thin text-xs">
                    <button onclick="filterCategory(null)" id="cat-pill-all" class="px-3 py-1.5 rounded-xl bg-indigo-600 text-white font-bold whitespace-nowrap transition">All Products</button>
                    <!-- Dynamically populated category pills -->
                </div>

                <!-- Products Grid (With robust layout preventing squishing) -->
                <div class="flex-1 min-h-0 overflow-y-auto pr-1">
                    <div id="products-grid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-3">
                        <div class="col-span-full flex items-center justify-center text-slate-500 text-xs py-16">
                            Loading WooCommerce products...
                        </div>
                    </div>
                    <!-- Bug fix: browsing previously had no way to reach
                         anything past the first ~100 products (WooCommerce's
                         default newest-first order, with no pagination at
                         all). This button loads and appends the next page. -->
                    <button id="load-more-products-btn" onclick="loadMoreProducts()" class="hidden w-full mt-3 py-2.5 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-xl text-xs font-bold text-slate-300 transition">
                        Load More Products
                    </button>
                </div>

                <!-- Mobile-only floating button to open the cart as a full-screen view -->
                <button id="mobile-cart-toggle" onclick="openMobileCart()" class="hidden lg:hidden fixed bottom-5 left-1/2 -translate-x-1/2 z-30 bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs px-5 py-3 rounded-full shadow-2xl items-center space-x-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    <span id="mobile-cart-toggle-label">View Cart</span>
                </button>
            </main>

            <!-- Right: Active Cart -->
            <!-- Responsive fix: this was a permanently-visible, fixed 384px
                 column — on a phone screen that's often wider than the
                 viewport itself, making the cart unusable (or invisible)
                 alongside the product grid. Below lg, it's hidden by default
                 and shown as a full-screen view via the floating "View Cart"
                 button, with a "Back to Products" bar to return. -->
            <aside id="cart-aside" class="hidden lg:flex fixed lg:static inset-0 z-40 w-full lg:w-96 bg-slate-950 flex-col shrink-0">

                <!-- Mobile-only: back to product grid -->
                <div class="lg:hidden flex items-center px-3 py-2.5 border-b border-slate-800 bg-slate-900">
                    <button onclick="closeMobileCart()" class="flex items-center space-x-1.5 text-xs text-slate-300 font-bold">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                        <span>Back to Products</span>
                    </button>
                </div>
                
                <!-- Customer Selection Bar -->
                <div class="p-3 bg-slate-900 border-b border-slate-800 flex items-center justify-between">
                    <div class="flex items-center space-x-2 truncate">
                        <div class="w-7 h-7 rounded-full bg-indigo-600/30 border border-indigo-500/40 text-indigo-300 flex items-center justify-center text-xs font-bold shrink-0">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                        </div>
                        <div class="truncate">
                            <p id="current-customer-name" class="text-xs font-bold text-white truncate">Guest / Walk-In Customer</p>
                            <p id="current-customer-phone" class="text-[10px] text-slate-400 truncate">No account assigned</p>
                        </div>
                    </div>
                    <button onclick="openCustomerModal()" class="text-[11px] bg-slate-800 hover:bg-slate-700 text-indigo-300 px-2.5 py-1 rounded-lg border border-slate-700 transition shrink-0">
                        Select / Add
                    </button>
                </div>

                <!-- Cart Header -->
                <div class="px-4 py-2.5 border-b border-slate-800 flex items-center justify-between bg-slate-950">
                    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center space-x-2">
                        <span>Active Sale Cart</span>
                        <span id="cart-badge-count" class="bg-indigo-600/30 text-indigo-300 px-2 py-0.5 rounded-full text-[10px]">0 items</span>
                    </h2>
                    <div class="flex items-center space-x-2">
                        <button onclick="parkCurrentCart()" class="text-[11px] text-amber-400 hover:underline">Park Sale</button>
                        <span class="text-slate-700">|</span>
                        <button onclick="clearCart()" class="text-[11px] text-rose-400 hover:underline">Clear</button>
                    </div>
                </div>

                <!-- Bug fix (UX): the checkout panel (totals, payment tabs,
                     cash calculator, order note) could grow tall enough that
                     the Complete Sale button — the single most important
                     action on this screen — needed scrolling or zooming out
                     to even reach, especially with a large cart. Capping that
                     panel's own height (the previous attempt) still left the
                     button reachable only via an easy-to-miss internal
                     scrollbar. Fixed properly this time: cart items + totals
                     + payment details now share ONE scrollable region, and
                     the Total/Complete-Sale button are a sticky footer
                     (shrink-0, outside the scroll area) that's always
                     visible no matter how much is above it. -->
                <div class="flex-1 min-h-0 overflow-y-auto">
                    <!-- Cart Line Items -->
                    <div id="cart-items" class="min-h-[120px] p-3 space-y-2">
                        <div class="text-center text-slate-500 text-xs py-16">Cart is empty</div>
                    </div>

                    <!-- Totals + Payment Details -->
                    <div class="p-4 bg-slate-900 border-t border-slate-800 space-y-3">
                        <!-- Totals -->
                        <div class="space-y-1.5 text-xs">
                            <div class="flex justify-between text-slate-400"><span>Subtotal:</span><span id="cart-subtotal" class="font-mono">$0.00</span></div>
                            <div class="flex justify-between text-amber-400" id="cart-discount-row" style="display:none !important;">
                                <span>Discount:</span><span id="cart-discount-total" class="font-mono">-$0.00</span>
                            </div>
                            <div class="flex justify-between text-emerald-400 hidden" id="cart-coupon-row">
                                <span id="cart-coupon-label">Coupon:</span><span id="cart-coupon-total" class="font-mono">-$0.00</span>
                            </div>
                            <div class="flex justify-between text-slate-400"><span id="cart-tax-label">Tax (Est.):</span><span id="cart-tax" class="font-mono">$0.00</span></div>
                        </div>

                        <!-- Whole-order discount (separate from the per-item
                             % discount above). Three modes, mirroring the
                             flexibility of the per-item discount: a real
                             coupon code, or a manual percentage/fixed amount
                             — the latter two require manager PIN + the
                             override_wc_pos_prices capability, same as a
                             per-item discount, since a coupon code is
                             self-authorizing but an arbitrary percentage or
                             amount typed in at checkout is not. -->
                        <div class="space-y-1.5" id="order-discount-section">
                            <label class="text-slate-400 text-[11px]">Order Discount</label>
                            <div class="grid grid-cols-3 gap-1.5 p-1 bg-slate-950 rounded-xl border border-slate-800 text-[11px]" id="order-discount-mode-tabs">
                                <button onclick="setOrderDiscountMode('coupon')" id="odmode-btn-coupon" class="py-1.5 rounded-lg bg-indigo-600 text-white font-bold transition">Coupon</button>
                                <button onclick="setOrderDiscountMode('percent')" id="odmode-btn-percent" class="py-1.5 rounded-lg text-slate-400 hover:text-white font-bold transition">Percent %</button>
                                <button onclick="setOrderDiscountMode('fixed')" id="odmode-btn-fixed" class="py-1.5 rounded-lg text-slate-400 hover:text-white font-bold transition">Fixed Amt</button>
                            </div>

                            <div class="flex space-x-1.5" id="order-discount-input-row">
                                <input type="text" id="coupon-code-input" placeholder="e.g. SAVE10"
                                    class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500 uppercase" />
                                <input type="number" id="order-discount-value-input" min="0" step="0.01" placeholder="0"
                                    class="hidden flex-1 bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-emerald-500" />
                                <button onclick="applyOrderDiscount()" id="order-discount-apply-btn" class="px-3 py-1.5 bg-slate-700 hover:bg-slate-600 text-white text-xs font-bold rounded-lg transition">Apply</button>
                            </div>
                            <div class="hidden items-center justify-between bg-emerald-500/10 border border-emerald-500/30 rounded-lg px-2.5 py-1.5" id="coupon-applied-row">
                                <span class="text-[11px] text-emerald-400 font-bold" id="coupon-applied-label"></span>
                                <button onclick="removeCouponCode()" class="text-rose-400 hover:text-rose-300 text-xs font-bold">Remove</button>
                            </div>
                            <p class="hidden text-[11px] text-rose-400" id="coupon-error"></p>
                        </div>

                        <!-- Payment Method Tabs -->
                        <div class="grid grid-cols-4 gap-1.5 p-1 bg-slate-950 rounded-xl border border-slate-800 text-xs">
                            <button onclick="setPaymentMethod('cash')" id="pay-btn-cash" class="py-1.5 rounded-lg bg-indigo-600 text-white font-bold text-[11px] transition">Cash</button>
                            <button onclick="setPaymentMethod('card')" id="pay-btn-card" class="py-1.5 rounded-lg text-slate-400 hover:text-white font-bold text-[11px] transition">Card</button>
                            <button onclick="setPaymentMethod('transfer')" id="pay-btn-transfer" class="py-1.5 rounded-lg text-slate-400 hover:text-white font-bold text-[11px] transition">Transfer</button>
                            <button onclick="setPaymentMethod('split')" id="pay-btn-split" class="py-1.5 rounded-lg text-slate-400 hover:text-white font-bold text-[11px] transition">Split</button>
                        </div>

                        <!-- Cash tendered / change calculator (cash mode) -->
                        <div id="cash-calc-panel" class="space-y-2 text-xs">
                            <div class="flex items-center space-x-2">
                                <label class="text-slate-400 shrink-0 w-20">Tendered:</label>
                                <input type="number" id="cash-tendered" min="0" step="0.01" placeholder="0.00"
                                    oninput="updateChangeDue()"
                                    class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-2 py-1.5 font-mono text-white text-xs focus:outline-none focus:border-indigo-500" />
                            </div>
                            <div class="flex items-center justify-between bg-slate-800 rounded-lg px-3 py-2">
                                <span class="text-slate-400">Change Due:</span>
                                <span id="change-due" class="font-mono font-bold text-emerald-400">$0.00</span>
                            </div>
                            <!-- Quick-amount buttons -->
                            <div id="quick-amounts" class="grid grid-cols-4 gap-1"></div>
                        </div>

                        <!-- Bank Transfer confirmation (no calculator needed —
                             use the Order Note field below for the transfer
                             reference/confirmation code) -->
                        <div id="transfer-panel" class="hidden space-y-2 text-xs">
                            <div class="flex items-center justify-between bg-slate-800 rounded-lg px-3 py-2">
                                <span class="text-slate-400">Amount Due:</span>
                                <span id="transfer-amount-due" class="font-mono font-bold text-emerald-400">$0.00</span>
                            </div>
                            <p class="text-slate-500 text-[11px]">Confirm the transfer has been received before completing the sale. Add the transfer reference in the Order Note field below.</p>
                        </div>

                        <!-- Split payment panel -->
                        <div id="split-payment-panel" class="hidden space-y-2 text-xs">
                            <p class="text-slate-400 text-[11px]">Enter amounts for each method. They must sum to the total.</p>
                            <div class="flex items-center space-x-2">
                                <label class="text-slate-400 shrink-0 w-14">Cash:</label>
                                <input type="number" id="split-cash" min="0" step="0.01" placeholder="0.00"
                                    oninput="updateSplitBalance()"
                                    class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-2 py-1.5 font-mono text-white text-xs focus:outline-none focus:border-emerald-500" />
                            </div>
                            <div class="flex items-center space-x-2">
                                <label class="text-slate-400 shrink-0 w-14">Card:</label>
                                <input type="number" id="split-card" min="0" step="0.01" placeholder="0.00"
                                    oninput="updateSplitBalance()"
                                    class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-2 py-1.5 font-mono text-white text-xs focus:outline-none focus:border-emerald-500" />
                            </div>
                            <div class="flex items-center space-x-2">
                                <label class="text-slate-400 shrink-0 w-14">Transfer:</label>
                                <input type="number" id="split-transfer" min="0" step="0.01" placeholder="0.00"
                                    oninput="updateSplitBalance()"
                                    class="flex-1 bg-slate-800 border border-slate-700 rounded-lg px-2 py-1.5 font-mono text-white text-xs focus:outline-none focus:border-emerald-500" />
                            </div>
                            <div class="flex items-center justify-between bg-slate-800 rounded-lg px-3 py-2">
                                <span class="text-slate-400">Remaining:</span>
                                <span id="split-remaining" class="font-mono font-bold text-amber-400">$0.00</span>
                            </div>
                        </div>

                        <!-- Fulfillment: Pickup vs Delivery -->
                        <div class="space-y-1.5">
                            <label class="text-slate-400 text-[11px]">Fulfillment</label>
                            <div class="grid grid-cols-2 gap-1.5 p-1 bg-slate-950 rounded-xl border border-slate-800 text-xs">
                                <button onclick="setFulfillmentType('pickup')" id="fulfill-btn-pickup" class="py-1.5 rounded-lg bg-indigo-600 text-white font-bold text-[11px] transition">Pickup</button>
                                <button onclick="setFulfillmentType('delivery')" id="fulfill-btn-delivery" class="py-1.5 rounded-lg text-slate-400 hover:text-white font-bold text-[11px] transition">Delivery</button>
                            </div>
                            <div id="delivery-address-row" class="hidden space-y-1">
                                <textarea id="delivery-address-input" rows="2" maxlength="500"
                                    placeholder="Delivery address (required for delivery orders)..."
                                    class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 resize-none"></textarea>
                            </div>
                        </div>

                        <!-- Order Note / Terminal Reference (Bug fix #3) -->
                        <div class="space-y-1">
                            <label for="pos-order-note" class="text-slate-400 text-[11px]">Order Note / Terminal Reference (optional)</label>
                            <textarea id="pos-order-note" rows="2" maxlength="1000"
                                placeholder="e.g. Terminal ref #4471, manual card auth code..."
                                class="w-full bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 resize-none"></textarea>
                        </div>
                    </div>
                </div>

                <!-- Sticky footer: Total + Complete Sale — always visible,
                     never part of the scrollable region above. -->
                <div class="shrink-0 p-4 bg-slate-900 border-t border-slate-700 space-y-2.5 shadow-[0_-4px_12px_rgba(0,0,0,0.3)]">
                    <div class="flex justify-between text-white text-base font-bold">
                        <span>TOTAL:</span><span id="cart-total" class="text-emerald-400 font-mono text-lg">$0.00</span>
                    </div>
                    <button onclick="processCheckout()" id="btn-checkout" disabled class="w-full py-3 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 disabled:hover:bg-emerald-600 text-white font-extrabold text-sm rounded-xl transition shadow-lg flex items-center justify-center space-x-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span>COMPLETE SALE &amp; PRINT RECEIPT</span>
                    </button>
                </div>
            </aside>
        </div>

        <!-- VIEW 2: Order Receipts History -->
        <div id="view-history" class="hidden flex-1 p-6 flex flex-col space-y-4 overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-white">POS Transaction Receipts</h2>
                    <p class="text-xs text-slate-400">View and reprint thermal receipts for recent in-person sales</p>
                </div>
                <button onclick="loadOrderHistory()" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold px-3 py-2 rounded-xl transition">
                    Reload History
                </button>
            </div>

            <div id="history-orders-list" class="flex-1 overflow-y-auto space-y-2 pr-1">
                <div class="text-center text-slate-500 text-xs py-12">Loading order history...</div>
            </div>
        </div>

        <!-- VIEW 3: Parked Carts -->
        <div id="view-parked" class="hidden flex-1 p-6 flex flex-col space-y-4 overflow-hidden">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-base font-bold text-white">Parked Sales Carts</h2>
                    <p class="text-xs text-slate-400">Temporarily saved carts waiting for customer return</p>
                </div>
            </div>

            <div id="parked-carts-list" class="flex-1 overflow-y-auto space-y-3">
                <div class="text-center text-slate-500 text-xs py-12">No parked carts stored</div>
            </div>
        </div>
    </div>

    <!-- MODAL 1: Product Variations Selector -->
    <div id="variation-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-md p-5 space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 id="var-modal-title" class="font-bold text-sm text-white">Select Variation</h3>
                <button onclick="closeVariationModal()" class="text-slate-400 hover:text-white text-lg">&times;</button>
            </div>
            <div id="var-modal-list" class="space-y-2 max-h-80 overflow-y-auto pr-1"></div>
        </div>
    </div>

    <!-- MODAL 2: Customer Selector & Add -->
    <div id="customer-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-md p-5 space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 class="font-bold text-sm text-white">Assign Store Customer</h3>
                <button onclick="closeCustomerModal()" class="text-slate-400 hover:text-white text-lg">&times;</button>
            </div>

            <button onclick="selectCustomer(null)" class="w-full bg-slate-800 hover:bg-slate-700 text-slate-200 text-xs font-bold py-2.5 rounded-xl border border-slate-700 transition">
                Set as Walk-In Guest Customer
            </button>

            <div class="relative">
                <input type="text" id="cust-search-input" oninput="searchCustomers()" placeholder="Search existing customer name or email..." class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3.5 py-2 text-xs text-white placeholder-slate-400 focus:outline-none focus:border-indigo-500" />
            </div>

            <div id="cust-search-results" class="space-y-1.5 max-h-40 overflow-y-auto text-xs"></div>

            <hr class="border-slate-800" />

            <div class="space-y-2">
                <h4 class="text-xs font-bold text-indigo-300 uppercase tracking-wider">+ Register New Customer</h4>
                <input type="text" id="new-cust-name" placeholder="Full Name" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white" />
                <input type="email" id="new-cust-email" placeholder="Email Address (Optional)" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white" />
                <input type="text" id="new-cust-phone" placeholder="Phone Number" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white" />
                <button onclick="addNewCustomer()" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold py-2 rounded-xl transition">
                    Create & Assign Customer
                </button>
            </div>
        </div>
    </div>

    <!-- OVERLAY: Terminal PIN Lock Screen -->
    <div id="lock-screen-overlay" class="hidden fixed inset-0 bg-slate-950/95 backdrop-blur-md z-50 flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-8 max-w-xs w-full text-center space-y-6 shadow-2xl">
            <div class="w-16 h-16 mx-auto bg-amber-500/20 border border-amber-500/40 rounded-2xl flex items-center justify-center text-amber-400">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </div>
            <div>
                <h3 class="text-base font-bold text-white">POS Terminal Locked</h3>
                <p class="text-xs text-slate-400 mt-1">Enter Cashier PIN or Password to unlock</p>
            </div>
            <input type="password" id="lock-pin-input" placeholder="Enter your cashier PIN" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-center text-lg font-mono text-white focus:outline-none focus:border-amber-500" />
            <button onclick="unlockTerminal()" class="w-full bg-amber-600 hover:bg-amber-500 text-white font-extrabold text-xs py-3 rounded-xl transition">
                UNLOCK TERMINAL
            </button>
        </div>
    </div>

    <!-- OVERLAY: Branch / Register Picker (multi-branch feature) -->
    <div id="branch-picker-overlay" class="hidden fixed inset-0 bg-slate-950/95 backdrop-blur-md z-[70] flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-8 max-w-sm w-full space-y-5 shadow-2xl">
            <div class="text-center">
                <h3 class="text-base font-bold text-white">Select Branch &amp; Register</h3>
                <p class="text-xs text-slate-400 mt-1">Determines which branch's stock and orders this terminal uses.</p>
            </div>
            <div class="space-y-1.5">
                <label class="text-xs text-slate-400">Branch</label>
                <select id="branch-picker-select" onchange="onBranchPickerChange()" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                    <option value="">Loading branches...</option>
                </select>
            </div>
            <div class="space-y-1.5">
                <label class="text-xs text-slate-400">Register</label>
                <select id="register-picker-select" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2.5 text-xs text-white focus:outline-none focus:border-indigo-500">
                    <option value="">Select a branch first</option>
                </select>
            </div>
            <div id="branch-picker-error" class="hidden text-[11px] text-rose-400 bg-rose-500/10 border border-rose-500/30 rounded-lg px-3 py-2"></div>
            <button onclick="confirmBranchPicker()" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-extrabold text-xs py-3 rounded-xl transition">
                CONFIRM
            </button>
        </div>
    </div>

    <!-- OVERLAY: Shift Open/Close -->
    <div id="shift-modal-overlay" class="hidden fixed inset-0 bg-slate-950/95 backdrop-blur-md z-[70] flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl p-8 max-w-sm w-full space-y-5 shadow-2xl">
            <div class="text-center">
                <h3 class="text-base font-bold text-white" id="shift-modal-title">Open Register Shift</h3>
                <p class="text-xs text-slate-400 mt-1" id="shift-modal-subtitle"></p>
            </div>

            <!-- Open-shift fields -->
            <div id="shift-open-fields" class="space-y-3">
                <div class="space-y-1.5">
                    <label class="text-xs text-slate-400">Opening Float</label>
                    <input type="number" id="shift-opening-float" min="0" step="0.01" value="0.00" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2.5 text-xs text-white font-mono focus:outline-none focus:border-indigo-500" />
                </div>
                <div class="space-y-1.5">
                    <label class="text-xs text-slate-400">Notes (optional)</label>
                    <textarea id="shift-open-notes" rows="2" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-indigo-500 resize-none"></textarea>
                </div>
            </div>

            <!-- Close-shift fields -->
            <div id="shift-close-fields" class="hidden space-y-3">
                <div class="space-y-1.5">
                    <label class="text-xs text-slate-400">Actual Cash Counted</label>
                    <input type="number" id="shift-actual-cash" min="0" step="0.01" value="0.00" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2.5 text-xs text-white font-mono focus:outline-none focus:border-indigo-500" />
                </div>
                <div class="space-y-1.5">
                    <label class="text-xs text-slate-400">Notes (optional)</label>
                    <textarea id="shift-close-notes" rows="2" class="w-full bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white focus:outline-none focus:border-indigo-500 resize-none"></textarea>
                </div>
                <div id="shift-close-summary" class="hidden bg-slate-800 rounded-xl p-3 text-[11px] text-slate-300 space-y-1"></div>
            </div>

            <div id="shift-modal-error" class="hidden text-[11px] text-rose-400 bg-rose-500/10 border border-rose-500/30 rounded-lg px-3 py-2"></div>

            <div class="flex space-x-2">
                <button onclick="closeShiftModal()" class="flex-1 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs py-3 rounded-xl transition">CANCEL</button>
                <button onclick="submitShiftAction()" id="shift-modal-submit-btn" class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white font-extrabold text-xs py-3 rounded-xl transition">OPEN SHIFT</button>
            </div>
        </div>
    </div>

    <!-- Hidden Printable Receipt Template -->
    <div id="printable-receipt" class="hidden p-6 font-mono text-xs text-black bg-white">
        <!-- Receipt width set dynamically from config -->
        <div id="receipt-logo-block" class="text-center mb-2"></div>
        <div id="receipt-store-name-block" class="text-center font-bold text-sm mb-1"></div>
        <div id="receipt-address-block" class="text-center text-[10px] text-gray-600 whitespace-pre-line mb-1"></div>
        <div id="receipt-header-block" class="text-center border-t border-dashed border-gray-400 pt-2 mb-2"></div>
        <div class="flex justify-between text-[10px] text-gray-500 border-b border-dashed border-gray-300 pb-1 mb-1">
            <span id="receipt-order-meta"></span>
            <span id="receipt-date-meta"></span>
        </div>
        <div id="receipt-items-block" class="mb-2 space-y-0.5"></div>
        <div class="border-t border-dashed border-gray-400 pt-1 space-y-0.5 text-xs">
            <div class="flex justify-between"><span>Subtotal</span><span id="receipt-subtotal"></span></div>
            <div id="receipt-discount-block" class="flex justify-between text-amber-700 hidden"><span>Discount</span><span id="receipt-discount-val"></span></div>
            <div id="receipt-tax-block" class="flex justify-between text-gray-600"><span id="receipt-tax-label">Tax</span><span id="receipt-tax-val"></span></div>
            <div class="flex justify-between font-bold text-sm border-t border-gray-400 pt-1"><span>TOTAL</span><span id="receipt-total-val"></span></div>
            <div id="receipt-payment-block" class="text-[10px] text-gray-600 mt-1"></div>
            <div id="receipt-change-block" class="flex justify-between text-[11px] font-bold hidden"><span>Change Given</span><span id="receipt-change-val"></span></div>
        </div>
        <div id="receipt-cashier-block" class="text-[10px] text-gray-500 mt-2"></div>
        <div id="receipt-barcode-block" class="text-center my-3 border border-dashed border-gray-300 py-2 text-[10px] text-gray-400">&#9635; ORDER BARCODE</div>
        <div id="receipt-footer-block" class="text-center text-[10px] text-gray-600 border-t border-dashed border-gray-400 pt-2"></div>
    </div>

    <!-- MODAL: Discount Entry -->
    <div id="discount-modal" class="hidden fixed inset-0 bg-black/70 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-xs p-5 space-y-4 shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                <h3 id="discount-modal-title" class="font-bold text-sm text-white">Apply Discount</h3>
                <button onclick="closeDiscountModal()" class="text-slate-400 hover:text-white text-lg">&times;</button>
            </div>
            <p id="discount-item-name" class="text-xs text-slate-400 truncate"></p>
            <div class="grid grid-cols-2 gap-2">
                <button onclick="setDiscountType('percent')" id="disc-btn-pct"
                    class="py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold transition">% Off</button>
                <button onclick="setDiscountType('flat')" id="disc-btn-flat"
                    class="py-2 rounded-xl bg-slate-700 text-slate-300 text-xs font-bold transition">Flat Amount</button>
            </div>
            <div class="flex items-center space-x-2">
                <span id="disc-prefix" class="text-slate-400 text-sm font-mono"></span>
                <input type="number" id="discount-value-input" min="0" step="0.01" placeholder="0"
                    class="flex-1 bg-slate-800 border border-slate-700 rounded-xl px-3 py-2 text-white text-sm font-mono focus:outline-none focus:border-indigo-500" />
            </div>
            <button onclick="applyItemDiscount()" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold py-2.5 rounded-xl transition">Apply Discount</button>
        </div>
    </div>

    <!-- MODAL: Manager PIN gate (for discount override) -->
    <div id="manager-pin-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-amber-500/40 rounded-2xl w-full max-w-xs p-6 space-y-4 shadow-2xl text-center">
            <div class="w-12 h-12 mx-auto bg-amber-500/20 rounded-xl flex items-center justify-center text-amber-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
            </div>
            <h3 class="font-bold text-white">Manager Authorization</h3>
            <p class="text-xs text-slate-400">Enter manager PIN to authorize this discount</p>
            <input type="password" id="manager-pin-input" placeholder="Manager PIN"
                class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-3 text-center text-lg font-mono text-white focus:outline-none focus:border-amber-500" />
            <button onclick="verifyManagerPin()" class="w-full bg-amber-600 hover:bg-amber-500 text-white font-bold text-xs py-3 rounded-xl transition">Authorize</button>
            <button onclick="closeManagerPinModal()" class="text-slate-500 hover:text-slate-300 text-xs">Cancel</button>
        </div>
    </div>

    <!-- OVERLAY: Change My PIN (self-service) -->
    <div id="change-pin-modal" class="hidden fixed inset-0 bg-black/80 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-2xl w-full max-w-xs p-6 space-y-4 shadow-2xl">
            <div class="text-center">
                <h3 class="font-bold text-white">Change My PIN</h3>
                <p class="text-xs text-slate-400 mt-1">Requires your current PIN.</p>
            </div>
            <div class="space-y-2">
                <input type="password" id="change-pin-current" placeholder="Current PIN" autocomplete="off"
                    class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-2.5 text-center font-mono text-white text-sm focus:outline-none focus:border-indigo-500" />
                <input type="password" id="change-pin-new" placeholder="New PIN (4–8 digits)" autocomplete="off"
                    class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-2.5 text-center font-mono text-white text-sm focus:outline-none focus:border-indigo-500" />
                <input type="password" id="change-pin-confirm" placeholder="Confirm New PIN" autocomplete="off"
                    class="w-full bg-slate-950 border border-slate-700 rounded-xl px-4 py-2.5 text-center font-mono text-white text-sm focus:outline-none focus:border-indigo-500" />
            </div>
            <div id="change-pin-error" class="hidden text-[11px] text-rose-400 bg-rose-500/10 border border-rose-500/30 rounded-lg px-3 py-2"></div>
            <div class="flex space-x-2">
                <button onclick="closeChangePinModal()" class="flex-1 bg-slate-800 hover:bg-slate-700 text-slate-300 font-bold text-xs py-3 rounded-xl transition">CANCEL</button>
                <button onclick="submitChangePin()" id="change-pin-submit-btn" class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white font-extrabold text-xs py-3 rounded-xl transition">SAVE</button>
            </div>
        </div>
    </div>

    <script>
        const restUrl = '<?php echo esc_js( $rest_url ); ?>';
        const restNonce = '<?php echo esc_js( $rest_nonce ); ?>';
        const currencySymbol = '<?php echo esc_js( $currency_symbol ); ?>';
        const LOW_STOCK_THRESHOLD = 5; // warn when stock at or below this value

        // Tax rate seeded from server; may be overridden by POS tax rates fetched via API.
        let taxRate = <?php
            $tax_rate = 0;
            if ( class_exists( 'WC_Tax' ) ) {
                $rates = WC_Tax::get_rates();
                if ( ! empty( $rates ) ) {
                    $first_rate = reset( $rates );
                    $tax_rate = isset( $first_rate['rate'] ) ? floatval( $first_rate['rate'] ) / 100 : 0;
                }
            }
            echo json_encode( $tax_rate );
        ?>;
        let taxInclusive = false;
        let receiptConfig = {};
        let posTaxRates  = [];

        let products = [];
        let categories = [];
        let selectedCategory = null;
        let cart = [];
        // Whole-order coupon discount (separate from per-item discounts).
        // { code, discountType, discountAmount } once successfully applied.
        let appliedOrderDiscount = null;
        let selectedCustomer = null;
        let selectedPaymentMethod = 'cash';
        // Delivery feature: 'pickup' (default) or 'delivery'. The HTML for
        // this already existed (buttons, address field) but the JS behind
        // it was never actually written — setFulfillmentType() below is new.
        let currentFulfillmentType = 'pickup';
        let parkedCarts = JSON.parse(localStorage.getItem('wc_pos_parked_carts') || '[]');
        let currentTab = 'register';
        // Multi-branch feature: persisted branch/register selection.
        let currentBranchId = localStorage.getItem('wc_pos_branch_id') || '';
        let currentRegisterId = localStorage.getItem('wc_pos_register_id') || '';
        let branchPickerBranches = [];
        let searchCustomerResults = [];

        // Discount state
        let discountTargetKey  = null;
        let discountType       = 'percent';
        let managerPinCallback = null;

        const DEMO_PRODUCTS = [
            { id: 101, name: 'Classic Organic Cotton Crew Tee', type: 'variable', sku: 'TSH-ORG-01', price: 32.00, regularPrice: 38.00, stockQuantity: 42, imageUrl: 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?w=500&auto=format&fit=crop&q=80', variations: [
                { id: 1011, name: 'Black - Medium', sku: 'TSH-ORG-BLK-M', price: 32.00, stockQuantity: 15, imageUrl: 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?w=500&auto=format&fit=crop&q=80' },
                { id: 1012, name: 'Navy - Large', sku: 'TSH-ORG-NAV-L', price: 38.00, stockQuantity: 12, imageUrl: 'https://images.unsplash.com/photo-1618354691373-d851c5c3a990?w=500&auto=format&fit=crop&q=80' }
            ]},
            { id: 102, name: 'Wireless Noise-Canceling Headphones', type: 'simple', sku: 'AUDIO-ANC-PRO', price: 199.00, regularPrice: 249.00, stockQuantity: 18, imageUrl: 'https://images.unsplash.com/photo-1505740420928-5e560c06d30e?w=500&auto=format&fit=crop&q=80' },
            { id: 103, name: 'Minimalist Stainless Steel Water Bottle 750ml', type: 'simple', sku: 'ACC-BOT-750', price: 28.00, regularPrice: 28.00, stockQuantity: 34, imageUrl: 'https://images.unsplash.com/photo-1602143407151-7111542de6e8?w=500&auto=format&fit=crop&q=80' },
            { id: 104, name: 'Artisan Espresso Roast Whole Bean Coffee 1kg', type: 'simple', sku: 'COF-ESP-1KG', price: 24.50, regularPrice: 24.50, stockQuantity: 60, imageUrl: 'https://images.unsplash.com/photo-1559056199-641a0ac8b55e?w=500&auto=format&fit=crop&q=80' },
            { id: 105, name: 'Leather Urban Commuter Backpack 22L', type: 'simple', sku: 'BAG-LTH-22L', price: 125.00, regularPrice: 145.00, stockQuantity: 9, imageUrl: 'https://images.unsplash.com/photo-1553062407-98eeb64c6a62?w=500&auto=format&fit=crop&q=80' },
            { id: 106, name: 'Retro Lightweight Running Sneakers', type: 'simple', sku: 'SNK-RTR-02', price: 89.00, regularPrice: 89.00, stockQuantity: 28, imageUrl: 'https://images.unsplash.com/photo-1542291026-7eec264c27ff?w=500&auto=format&fit=crop&q=80' }
        ];

        function toggleSidebar() {
            const sb = document.getElementById('pos-sidebar');
            const backdrop = document.getElementById('pos-sidebar-backdrop');
            if (sb) {
                // Bug fix: previously only toggled 'hidden', which left the
                // element with no display utility active below the lg
                // breakpoint once shown (an <aside> defaults to display:block,
                // breaking the flex-column icon layout). Toggling 'flex'
                // alongside it ensures it actually lays out as a flex column
                // when opened as a mobile overlay.
                sb.classList.toggle('hidden');
                sb.classList.toggle('flex');
            }
            if (backdrop) {
                backdrop.classList.toggle('hidden');
            }
        }

        // ---------------------------------------------------------------
        // Responsive fix: mobile cart view. Below the lg breakpoint the cart
        // aside is a hidden, full-screen overlay rather than a permanent
        // side column — these open/close it, and the floating button's
        // visibility/label is kept in sync from renderCart().
        // ---------------------------------------------------------------

        function openMobileCart() {
            const aside = document.getElementById('cart-aside');
            if (aside) {
                aside.classList.remove('hidden');
                aside.classList.add('flex');
            }
        }

        function closeMobileCart() {
            const aside = document.getElementById('cart-aside');
            if (aside) {
                aside.classList.add('hidden');
                aside.classList.remove('flex');
            }
        }

        function updateMobileCartToggle() {
            const btn = document.getElementById('mobile-cart-toggle');
            const label = document.getElementById('mobile-cart-toggle-label');
            if (!btn || !label) return;

            if (cart.length === 0) {
                btn.classList.add('hidden');
                btn.classList.remove('flex');
                return;
            }

            const totalQty = cart.reduce((acc, c) => acc + c.quantity, 0);
            const grandTotal = cart.reduce((acc, c) => acc + (c.unitPrice * c.quantity) - (c.discountAmount || 0), 0);
            label.textContent = 'View Cart (' + totalQty + ') \u2022 ' + currencySymbol + grandTotal.toFixed(2);
            btn.classList.remove('hidden');
            btn.classList.add('flex');
        }

        // Dark/Light Theme Handler
        function initTheme() {
            if (localStorage.getItem('wc_pos_theme') === 'light') {
                document.documentElement.classList.remove('dark');
                document.documentElement.classList.add('light');
                const sun = document.getElementById('theme-icon-sun');
                const moon = document.getElementById('theme-icon-moon');
                if (sun) sun.classList.remove('hidden');
                if (moon) moon.classList.add('hidden');
            } else {
                document.documentElement.classList.add('dark');
                document.documentElement.classList.remove('light');
            }
        }

        function toggleTheme() {
            const isDark = document.documentElement.classList.contains('dark');
            if (isDark) {
                document.documentElement.classList.remove('dark');
                document.documentElement.classList.add('light');
                localStorage.setItem('wc_pos_theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                document.documentElement.classList.remove('light');
                localStorage.setItem('wc_pos_theme', 'dark');
            }
            const sun = document.getElementById('theme-icon-sun');
            const moon = document.getElementById('theme-icon-moon');
            if (sun) sun.classList.toggle('hidden', !isDark);
            if (moon) moon.classList.toggle('hidden', isDark);
        }

        // ---------------------------------------------------------------
        // Multi-branch feature: Branch / Register Picker
        // ---------------------------------------------------------------

        function updateBranchRegisterLabel() {
            const label = document.getElementById('branch-register-label');
            if (!label) return;
            if (currentBranchId && currentRegisterId) {
                const branch = branchPickerBranches.find(b => b.id === currentBranchId);
                label.textContent = (branch ? branch.name : currentBranchId) + ' — ' + currentRegisterId;
            } else {
                label.textContent = 'Select Branch';
            }
        }

        // Bug fix: previously branchPickerBranches only got populated when the
        // picker modal was actually opened. On a returning session (branch
        // already remembered from localStorage), the picker never opens, so
        // this stayed an empty array all session — meaning the header label
        // could never resolve a branch NAME and always fell back to showing
        // the raw ID instead (e.g. "default" or "br_gKsdCZ4HKQAR"). This is
        // now called unconditionally on every load.
        async function loadBranchesList() {
            try {
                const res = await fetch(restUrl + '/branches', { headers: { 'X-WP-Nonce': restNonce } });
                const json = await res.json();
                branchPickerBranches = (json && json.data) ? json.data.filter(b => b.status === 'active') : [];
            } catch (e) {
                branchPickerBranches = [];
            }
            updateBranchRegisterLabel();
        }

        async function openBranchPicker() {
            const errorBox = document.getElementById('branch-picker-error');
            errorBox.classList.add('hidden');
            document.getElementById('branch-picker-overlay').classList.remove('hidden');

            const branchSelect = document.getElementById('branch-picker-select');
            branchSelect.innerHTML = '<option value="">Loading branches...</option>';

            await loadBranchesList();

            if (branchPickerBranches.length === 0) {
                branchSelect.innerHTML = '<option value="">No active branches found</option>';
                errorBox.textContent = 'No active branches configured. Ask a manager to set one up under POS > Branches.';
                errorBox.classList.remove('hidden');
                return;
            }

            branchSelect.innerHTML = branchPickerBranches.map(b =>
                '<option value="' + b.id + '"' + (b.id === currentBranchId ? ' selected' : '') + '>' + b.name + '</option>'
            ).join('');

            await loadRegistersForPicker(branchSelect.value || branchPickerBranches[0].id);
        }

        async function onBranchPickerChange() {
            const branchId = document.getElementById('branch-picker-select').value;
            await loadRegistersForPicker(branchId);
        }

        async function loadRegistersForPicker(branchId) {
            const registerSelect = document.getElementById('register-picker-select');
            registerSelect.innerHTML = '<option value="">Loading registers...</option>';

            if (!branchId) {
                registerSelect.innerHTML = '<option value="">Select a branch first</option>';
                return;
            }

            try {
                const res = await fetch(restUrl + '/registers?branchId=' + encodeURIComponent(branchId), { headers: { 'X-WP-Nonce': restNonce } });
                const registers = await res.json();

                if (!registers || registers.length === 0) {
                    registerSelect.innerHTML = '<option value="">No registers for this branch</option>';
                    return;
                }

                registerSelect.innerHTML = registers.map(r =>
                    '<option value="' + r.id + '"' + (r.id === currentRegisterId ? ' selected' : '') + '>' + r.name + (r.location ? ' (' + r.location + ')' : '') + '</option>'
                ).join('');
            } catch (e) {
                registerSelect.innerHTML = '<option value="">Could not load registers</option>';
            }
        }

        function confirmBranchPicker() {
            const branchId    = document.getElementById('branch-picker-select').value;
            const registerId  = document.getElementById('register-picker-select').value;
            const errorBox    = document.getElementById('branch-picker-error');

            if (!branchId || !registerId) {
                errorBox.textContent = 'Please select both a branch and a register.';
                errorBox.classList.remove('hidden');
                return;
            }

            currentBranchId = branchId;
            currentRegisterId = registerId;
            localStorage.setItem('wc_pos_branch_id', branchId);
            localStorage.setItem('wc_pos_register_id', registerId);

            document.getElementById('branch-picker-overlay').classList.add('hidden');
            updateBranchRegisterLabel();
            fetchProducts(); // re-fetch with branch-specific stock now that context changed
            refreshShiftStatus();
        }

        // ---------------------------------------------------------------
        // Shift open/close
        // ---------------------------------------------------------------

        let currentShiftStatus = null; // 'open' | 'closed' | null (unknown)

        async function refreshShiftStatus() {
            const indicator = document.getElementById('shift-indicator');
            if (!currentRegisterId) {
                indicator.classList.add('hidden');
                return;
            }

            let registers = null;
            try {
                const res = await fetch(restUrl + '/registers?branchId=' + encodeURIComponent(currentBranchId), { headers: { 'X-WP-Nonce': restNonce } });
                registers = await res.json();
            } catch (e) {
                // Network/parse failure — leave the stored selection alone
                // and just show an unknown state; don't treat a transient
                // error as proof the register doesn't exist.
                currentShiftStatus = null;
                updateShiftIndicator();
                return;
            }

            const reg = Array.isArray(registers) ? registers.find(r => r.id === currentRegisterId) : null;

            // Bug fix: a remembered branch/register pairing that no longer
            // corresponds to a real register (e.g. left over from earlier
            // testing, or the register was deleted/reassigned) previously
            // failed silently — the indicator would just show an ambiguous
            // state, and every action after that point (checkout, shift
            // open/close) was silently operating on a nonexistent register.
            // Detected explicitly now, but only once we've confirmed a real
            // response actually came back without that register in it —
            // clear the stale selection and require picking a real one again.
            if (!reg) {
                localStorage.removeItem('wc_pos_branch_id');
                localStorage.removeItem('wc_pos_register_id');
                currentBranchId = '';
                currentRegisterId = '';
                currentShiftStatus = null;
                updateBranchRegisterLabel();
                indicator.classList.add('hidden');
                openBranchPicker();
                return;
            }

            currentShiftStatus = reg.status;

            updateShiftIndicator();
        }

        function updateShiftIndicator() {
            const indicator = document.getElementById('shift-indicator');
            const label = document.getElementById('shift-indicator-label');
            if (!currentRegisterId) { indicator.classList.add('hidden'); return; }

            indicator.classList.remove('hidden');
            if (currentShiftStatus === 'open') {
                label.textContent = 'Shift: OPEN';
                indicator.className = 'text-[11px] px-2.5 py-1 rounded-lg font-mono transition border bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border-emerald-500/30';
            } else {
                label.textContent = 'Shift: CLOSED';
                indicator.className = 'text-[11px] px-2.5 py-1 rounded-lg font-mono transition border bg-slate-700/40 hover:bg-slate-700/60 text-slate-300 border-slate-600/40';
            }
        }

        function openShiftModal() {
            if (!currentBranchId || !currentRegisterId) {
                openBranchPicker();
                return;
            }

            document.getElementById('shift-modal-error').classList.add('hidden');
            document.getElementById('shift-close-summary').classList.add('hidden');
            const isOpen = currentShiftStatus === 'open';

            document.getElementById('shift-modal-title').textContent = isOpen ? 'Close Register Shift' : 'Open Register Shift';
            document.getElementById('shift-modal-subtitle').textContent = isOpen
                ? 'Count the drawer and enter the actual cash total.'
                : 'Enter the starting cash float for this register.';
            document.getElementById('shift-open-fields').classList.toggle('hidden', isOpen);
            document.getElementById('shift-close-fields').classList.toggle('hidden', !isOpen);
            document.getElementById('shift-modal-submit-btn').textContent = isOpen ? 'CLOSE SHIFT' : 'OPEN SHIFT';

            document.getElementById('shift-modal-overlay').classList.remove('hidden');
        }

        function closeShiftModal() {
            document.getElementById('shift-modal-overlay').classList.add('hidden');
        }

        async function submitShiftAction() {
            const isOpen = currentShiftStatus === 'open';
            const action = isOpen ? 'close' : 'open';
            const errorBox = document.getElementById('shift-modal-error');
            errorBox.classList.add('hidden');

            const payload = {
                action,
                registerId: currentRegisterId,
                branchId: currentBranchId,
            };
            if (action === 'open') {
                payload.openingFloat = parseFloat(document.getElementById('shift-opening-float').value) || 0;
                payload.notes = document.getElementById('shift-open-notes').value.trim();
            } else {
                payload.actualCash = parseFloat(document.getElementById('shift-actual-cash').value) || 0;
                payload.notes = document.getElementById('shift-close-notes').value.trim();
            }

            const btn = document.getElementById('shift-modal-submit-btn');
            btn.disabled = true;

            try {
                const res = await fetch(restUrl + '/registers/shift', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();

                if (!data.success) {
                    errorBox.textContent = data.message || 'Could not complete this action.';
                    errorBox.classList.remove('hidden');
                    return;
                }

                if (action === 'close' && data.summary) {
                    const s = data.summary;
                    const summaryBox = document.getElementById('shift-close-summary');
                    summaryBox.innerHTML =
                        '<div class="flex justify-between"><span>Total Sales:</span><span>' + currencySymbol + s.totalSales.toFixed(2) + '</span></div>' +
                        '<div class="flex justify-between"><span>Transfer Sales:</span><span>' + currencySymbol + (s.transferSales || 0).toFixed(2) + '</span></div>' +
                        '<div class="flex justify-between"><span>Expected Cash:</span><span>' + currencySymbol + s.expectedCash.toFixed(2) + '</span></div>' +
                        '<div class="flex justify-between"><span>Actual Cash:</span><span>' + currencySymbol + s.actualCash.toFixed(2) + '</span></div>' +
                        '<div class="flex justify-between font-bold ' + (s.cashDifference < 0 ? 'text-rose-400' : 'text-emerald-400') + '"><span>Difference:</span><span>' + currencySymbol + s.cashDifference.toFixed(2) + '</span></div>';
                    summaryBox.classList.remove('hidden');
                }

                currentShiftStatus = (action === 'open') ? 'open' : 'closed';
                updateShiftIndicator();

                if (action === 'open') {
                    closeShiftModal();
                }
                // On close, leave the modal open so the cashier can read the summary;
                // they dismiss it with Cancel once done.
            } catch (e) {
                errorBox.textContent = 'Network error. Please check your connection and try again.';
                errorBox.classList.remove('hidden');
            } finally {
                btn.disabled = false;
            }
        }

        // Terminal Lock Handler
        function lockTerminal() {
            document.getElementById('lock-screen-overlay').classList.remove('hidden');
            document.getElementById('lock-pin-input').value = '';
            document.getElementById('lock-pin-input').focus();
        }

        async function unlockTerminal() {
            const val = document.getElementById('lock-pin-input').value.trim();
            if ( ! val ) return;

            const btn = document.querySelector('#lock-screen-overlay button');
            if (btn) { btn.disabled = true; btn.textContent = 'Verifying...'; }

            try {
                const res = await fetch(restUrl + '/pin/verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                    body: JSON.stringify({ pin: val })
                });
                const data = await res.json();
                if (data.success) {
                    document.getElementById('lock-screen-overlay').classList.add('hidden');
                    document.getElementById('lock-pin-input').value = '';
                    if (data.requiresSetup) {
                        const newPin = prompt('Default PIN accepted. Please set a new personal PIN (4–8 digits):');
                        if (newPin && /^\d{4,8}$/.test(newPin)) {
                            await fetch(restUrl + '/pin/set', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                                body: JSON.stringify({ pin: newPin, currentPin: '1234' })
                            });
                            alert('PIN updated successfully.');
                        }
                    }
                } else {
                    document.getElementById('lock-pin-input').value = '';
                    document.getElementById('lock-pin-input').focus();
                    alert(data.message || 'Incorrect PIN. Please try again.');
                }
            } catch (e) {
                alert('Could not verify PIN. Check your connection.');
            } finally {
                if (btn) { btn.disabled = false; btn.textContent = 'UNLOCK TERMINAL'; }
            }
        }

        // Sidebar Navigation Tabs
        // Responsive fix: closes the mobile sidebar overlay (a no-op on
        // desktop, where the sidebar is permanently visible via lg:flex
        // rather than JS-toggled) — called whenever a sidebar nav action is
        // taken, so picking a view doesn't leave the overlay covering it.
        function closeMobileSidebarOverlay() {
            const sb = document.getElementById('pos-sidebar');
            const backdrop = document.getElementById('pos-sidebar-backdrop');
            if (sb && !sb.classList.contains('hidden') && window.innerWidth < 1024) {
                sb.classList.add('hidden');
                sb.classList.remove('flex');
                if (backdrop) backdrop.classList.add('hidden');
            }
        }

        function switchTab(tab) {
            closeMobileSidebarOverlay();
            currentTab = tab;
            ['register', 'history', 'parked'].forEach(t => {
                const el = document.getElementById('view-' + t);
                const btn = document.getElementById('nav-btn-' + t);
                if (el) el.classList.toggle('hidden', t !== tab);
                if (btn) {
                    btn.classList.toggle('bg-indigo-600', t === tab);
                    btn.classList.toggle('text-white', t === tab);
                }
            });

            if (tab === 'history') loadOrderHistory();
            if (tab === 'parked') renderParkedCarts();
        }

        // -----------------------------------------------------------------------
        // Config bootstrap — receipt config + POS tax rates
        // -----------------------------------------------------------------------

        async function loadConfig() {
            try {
                const [rcRes, txRes] = await Promise.all([
                    fetch(restUrl + '/receipt-config', { headers: { 'X-WP-Nonce': restNonce } }),
                    fetch(restUrl + '/tax-rates',      { headers: { 'X-WP-Nonce': restNonce } }),
                ]);
                if (rcRes.ok) receiptConfig = await rcRes.json();
                if (txRes.ok) {
                    const txData = await txRes.json();
                    posTaxRates  = txData.rates || [];
                    taxInclusive = txData.taxInclusivePrices || false;
                    // Use first active POS rate if defined, otherwise keep WC seeded rate
                    if (posTaxRates.length > 0) {
                        taxRate = posTaxRates[0].rate / 100;
                    }
                }
            } catch(e) { /* non-fatal — use seeded defaults */ }
        }

        // -----------------------------------------------------------------------
        // Receipt builder — populates #printable-receipt from receiptConfig
        // -----------------------------------------------------------------------

        function buildReceipt(orderData) {
            const cfg = receiptConfig;
            const w   = cfg.paperWidth === '58mm' ? '200px' : '280px';
            const el  = document.getElementById('printable-receipt');

            // Must be visible (display:block) before window.print() — display:none
            // is not overridden by visibility:visible in @media print.
            el.classList.remove('hidden');
            el.style.display  = 'block';
            el.style.maxWidth = w;

            // Safe fallbacks for stores that haven't configured the receipt builder yet.
            const showLogo       = cfg.showLogo       !== false;
            const showStoreName  = cfg.showStoreName  !== false;
            const showAddress    = cfg.showAddress    !== false;
            const showTax        = cfg.showTaxBreakdown !== false;
            const showCashier    = cfg.showCashier    !== false;
            const showBarcode    = cfg.showBarcode    !== false;
            const storeName      = cfg.storeName      || '<?php echo esc_js( $store_name ); ?>';
            const storeAddress   = cfg.storeAddress   || '<?php echo esc_js( get_option( "wc_pos_store_address", "" ) ); ?>';
            const storePhone     = cfg.storePhone     || '<?php echo esc_js( get_option( "wc_pos_store_phone", "" ) ); ?>';
            const headerText     = cfg.headerText     || '<?php echo esc_js( $receipt_header ); ?>';
            const footerText     = cfg.footerText     || '<?php echo esc_js( $receipt_footer ); ?>';
            const lineItemFormat = cfg.lineItemFormat || 'full';

            // Logo
            const logoBlock = document.getElementById('receipt-logo-block');
            logoBlock.innerHTML = (showLogo && cfg.logoUrl)
                ? '<img src="' + cfg.logoUrl + '" style="max-height:60px;max-width:' + w + ';object-fit:contain;" />'
                : '';

            // Store name
            document.getElementById('receipt-store-name-block').textContent =
                showStoreName ? storeName : '';

            // Address
            document.getElementById('receipt-address-block').textContent =
                showAddress ? (storeAddress + (storePhone ? '\n' + storePhone : '')) : '';

            // Header
            document.getElementById('receipt-header-block').textContent = headerText;

            // Order meta
            document.getElementById('receipt-order-meta').textContent = 'Order #' + (orderData.orderId || orderData.localId || '—');
            document.getElementById('receipt-date-meta').textContent  = new Date().toLocaleString();

            // Items
            const fmt = lineItemFormat;
            const itemsEl = document.getElementById('receipt-items-block');
            itemsEl.innerHTML = (orderData.items || []).map(item => {
                const lineTotal = (item.unitPrice * item.quantity) - (item.discountAmount || 0);
                if (fmt === 'minimal') {
                    return '<div style="display:flex;justify-content:space-between;"><span>' + item.name + '</span><span>' + currencySymbol + lineTotal.toFixed(2) + '</span></div>';
                } else if (fmt === 'compact') {
                    return '<div style="display:flex;justify-content:space-between;"><span>' + item.name + ' × ' + item.quantity + '</span><span>' + currencySymbol + lineTotal.toFixed(2) + '</span></div>';
                } else {
                    let line = '<div style="display:flex;justify-content:space-between;"><span>' + item.name + ' × ' + item.quantity + '</span><span>' + currencySymbol + lineTotal.toFixed(2) + '</span></div>';
                    if (item.sku) line += '<div style="font-size:10px;color:#999;">SKU: ' + item.sku + '</div>';
                    if (item.discountAmount > 0) line += '<div style="font-size:10px;color:#b45309;">Discount: -' + currencySymbol + item.discountAmount.toFixed(2) + '</div>';
                    return line;
                }
            }).join('');

            // Totals
            document.getElementById('receipt-subtotal').textContent    = currencySymbol + orderData.subtotal.toFixed(2);
            if (orderData.totalDiscount > 0) {
                document.getElementById('receipt-discount-block').classList.remove('hidden');
                document.getElementById('receipt-discount-val').textContent = '-' + currencySymbol + orderData.totalDiscount.toFixed(2);
            }
            document.getElementById('receipt-tax-label').textContent   = taxInclusive ? 'Tax (Incl.)' : 'Tax';
            document.getElementById('receipt-tax-block').classList.toggle('hidden', !showTax);
            document.getElementById('receipt-tax-val').textContent     = currencySymbol + orderData.tax.toFixed(2);
            document.getElementById('receipt-total-val').textContent   = currencySymbol + orderData.grandTotal.toFixed(2);

            // Payment summary
            const payLines = (orderData.payments || []).map(p =>
                (p.method.charAt(0).toUpperCase() + p.method.slice(1)) + ': ' + currencySymbol + parseFloat(p.amount).toFixed(2)
            ).join(' | ');
            document.getElementById('receipt-payment-block').textContent = payLines;

            // Change
            if (orderData.changeDue > 0) {
                document.getElementById('receipt-change-block').classList.remove('hidden');
                document.getElementById('receipt-change-val').textContent = currencySymbol + orderData.changeDue.toFixed(2);
            }

            // Cashier
            const cashierEl = document.getElementById('receipt-cashier-block');
            cashierEl.textContent   = showCashier ? 'Served by: ' + orderData.cashierName : '';
            cashierEl.style.display = showCashier ? 'block' : 'none';

            // Barcode
            const barcodeEl = document.getElementById('receipt-barcode-block');
            barcodeEl.style.display = showBarcode ? 'block' : 'none';
            barcodeEl.textContent   = '▊▊ ORDER #' + (orderData.orderId || '') + ' ▊▊';

            // Footer
            document.getElementById('receipt-footer-block').textContent = footerText;
        }

        // Fetch Categories
        async function fetchCategories() {
            try {
                const res = await fetch(restUrl + '/categories', { headers: { 'X-WP-Nonce': restNonce } });
                if (res.ok) {
                    categories = await res.json();
                } else {
                    categories = [
                        { id: 1, name: 'Apparel & Shirts', slug: 'apparel-shirts', count: 12 },
                        { id: 2, name: 'Footwear & Sneakers', slug: 'footwear-sneakers', count: 8 },
                        { id: 3, name: 'Electronics & Audio', slug: 'electronics-audio', count: 15 },
                        { id: 4, name: 'Accessories', slug: 'accessories', count: 20 },
                        { id: 5, name: 'Coffee & Beverages', slug: 'coffee-beverages', count: 6 }
                    ];
                }
            } catch (e) {
                categories = [
                    { id: 1, name: 'Apparel & Shirts', slug: 'apparel-shirts', count: 12 },
                    { id: 2, name: 'Footwear & Sneakers', slug: 'footwear-sneakers', count: 8 },
                    { id: 3, name: 'Electronics & Audio', slug: 'electronics-audio', count: 15 },
                    { id: 4, name: 'Accessories', slug: 'accessories', count: 20 },
                    { id: 5, name: 'Coffee & Beverages', slug: 'coffee-beverages', count: 6 }
                ];
            }
            renderCategories();
        }

        function renderCategories() {
            const bar = document.getElementById('category-pills-bar');
            if (!bar) return;
            const isAllActive = selectedCategory === null;
            let html = '<button onclick="filterCategory(null)" id="cat-pill-all" class="px-3 py-1.5 rounded-xl ' + (isAllActive ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-800 text-slate-300 font-medium hover:bg-slate-700') + ' whitespace-nowrap transition">All Products</button>';
            if (categories && categories.length > 0) {
                html += categories.map(c => {
                    const isActive = selectedCategory === c.slug;
                    const escapedSlug = c.slug.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                    return '<button onclick="filterCategory(\'' + escapedSlug + '\')" id="cat-pill-' + c.slug + '" class="px-3 py-1.5 rounded-xl ' + (isActive ? 'bg-indigo-600 text-white font-bold' : 'bg-slate-800 text-slate-300 font-medium hover:bg-slate-700') + ' whitespace-nowrap transition">' + c.name + ' (' + c.count + ')</button>';
                }).join('');
            }
            bar.innerHTML = html;
        }

        function filterCategory(slug) {
            selectedCategory = slug;
            renderCategories();
            fetchProducts();
        }

        // Fetch Catalog Products
        // Bug fixes:
        // (1) The product grid used to fetch a single hard-capped batch with
        //     no way to request more — anything beyond that batch (WooCommerce's
        //     newest-first default with no explicit ordering) was permanently
        //     unreachable in a catalog of any real size.
        // (2) The search box filtered that same capped batch client-side —
        //     it never actually queried the server, so anything not already
        //     loaded could never be found no matter how the store's full
        //     catalog was searched.
        // Fixed together: fetchProducts() now supports real pagination
        // (append=true loads and appends the next page) and always sends the
        // current search term to the server, which now returns a generous,
        // non-paginated batch of every match rather than a capped subset.
        let currentProductPage = 1;
        let productTotalPages  = 1;
        let productSearchTerm  = '';
        let isLoadingMoreProducts = false;

        async function fetchProducts(page = 1, append = false) {
            if (isLoadingMoreProducts) return;
            isLoadingMoreProducts = true;
            updateLoadMoreButton(true);

            try {
                const params = new URLSearchParams();
                if (selectedCategory) params.set('category', selectedCategory);
                if (currentBranchId) params.set('branchId', currentBranchId);
                if (productSearchTerm) params.set('s', productSearchTerm);
                params.set('page', page);

                const url = restUrl + '/products?' + params.toString();
                const res = await fetch(url, { headers: { 'X-WP-Nonce': restNonce } });
                if (!res.ok) throw new Error('API Error');
                const data = await res.json();

                const newProducts = (data && Array.isArray(data.products)) ? data.products : (Array.isArray(data) ? data : []);
                currentProductPage = (data && data.page) ? data.page : page;
                productTotalPages  = (data && data.totalPages) ? data.totalPages : 1;

                if (append) {
                    products = products.concat(newProducts);
                } else if (newProducts.length > 0) {
                    products = newProducts;
                } else if (page === 1 && !productSearchTerm) {
                    // Only fall back to demo data on a genuinely empty first
                    // load with no active search — an empty search result is
                    // a real "no matches", not a connection failure.
                    products = DEMO_PRODUCTS;
                } else {
                    products = [];
                }
            } catch (e) {
                if (!append) products = DEMO_PRODUCTS;
            } finally {
                isLoadingMoreProducts = false;
                updateLoadMoreButton(false);
            }
            renderProducts();
        }

        // Debounced server-side search — replaces the old client-side-only
        // filter so every keystroke (after a short pause) queries the full
        // catalog rather than whatever's already loaded in the browser.
        let productSearchDebounce = null;
        function onProductSearchInput() {
            const termInput = document.getElementById('product-search');
            productSearchTerm = termInput ? termInput.value.trim() : '';
            clearTimeout(productSearchDebounce);
            productSearchDebounce = setTimeout(() => {
                fetchProducts(1, false);
            }, 300);
        }

        function loadMoreProducts() {
            if (currentProductPage < productTotalPages) {
                fetchProducts(currentProductPage + 1, true);
            }
        }

        function updateLoadMoreButton(loading) {
            const btn = document.getElementById('load-more-products-btn');
            if (!btn) return;
            const hasMore = currentProductPage < productTotalPages;
            btn.classList.toggle('hidden', !hasMore && !loading);
            btn.disabled = loading;
            btn.textContent = loading ? 'Loading...' : ('Load More Products (' + (productTotalPages - currentProductPage) + ' more page' + (productTotalPages - currentProductPage === 1 ? '' : 's') + ')');
        }

        function renderProducts() {
            const grid = document.getElementById('products-grid');
            if (!grid) return;

            // Bug fix: this used to re-filter 'products' by whatever's typed
            // in the search box — redundant and actively wrong now that the
            // server performs the real search (see fetchProducts /
            // onProductSearchInput above). 'products' already IS the correct
            // set for the current search term, category, and page.
            const filtered = products || [];

            if (filtered.length === 0) {
                grid.innerHTML = '<div class="col-span-full text-slate-500 text-xs text-center py-12">No products found</div>';
                return;
            }

            grid.innerHTML = filtered.map(function(p) {
                var hasImg = p.imageUrl && p.imageUrl.trim().length > 0;
                var isVariable = p.type === 'variable';
                var priceVal = parseFloat(p.price || p.regularPrice || p.salePrice || 0);
                var displayPrice = isNaN(priceVal) ? '0.00' : priceVal.toFixed(2);
                // Bug fix (#1/#4): trust the backend's resolved stockStatus rather
                // than defaulting an undefined quantity to 10 (which silently
                // treated unknown/parent-level stock as "always available").
                // isProductOutOfStock() correctly aggregates variation stock for
                // variable products via the stockStatus field from the API.
                var stockQty = (p.stockQuantity !== undefined && p.stockQuantity !== null) ? p.stockQuantity : null;
                var outOfStock = isProductOutOfStock(p);
                var imgHtml = hasImg 
                    ? '<img src="' + p.imageUrl + '" alt="Product" class="w-full h-full object-contain group-hover:scale-105 transition duration-200" />'
                    : '<div class="text-slate-600 flex flex-col items-center"><svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg></div>';
                var varTag = isVariable ? '<span class="absolute top-2 right-2 bg-indigo-600 text-white text-[9px] font-extrabold px-1.5 py-0.5 rounded uppercase">Variations</span>' : '';
                var skuText = p.sku ? 'SKU: ' + p.sku : 'No SKU';
                var stockClass = outOfStock ? 'text-rose-400 font-bold' : (stockQty !== null && stockQty <= LOW_STOCK_THRESHOLD) ? 'text-amber-400 font-bold' : 'text-slate-400';
                var stockLabel = outOfStock ? '✕ Out of stock' : (stockQty !== null && stockQty <= LOW_STOCK_THRESHOLD) ? '⚠ Low: ' + stockQty : (stockQty !== null ? stockQty + ' in stock' : 'In stock');
                var cardClasses = 'pos-card bg-slate-800/90 border rounded-xl overflow-hidden transition flex flex-col justify-between shadow-md group min-h-[220px] '
                    + (outOfStock
                        ? 'border-rose-900/60 opacity-50 cursor-not-allowed'
                        : 'border-slate-700 hover:border-indigo-500 cursor-pointer hover:scale-[1.02]');
                var clickHandler = outOfStock
                    ? 'handleOutOfStockClick(' + p.id + ')'
                    : 'handleProductClick(' + p.id + ')';
                var oosBadge = outOfStock ? '<span class="absolute top-2 left-2 bg-rose-600 text-white text-[9px] font-extrabold px-1.5 py-0.5 rounded uppercase">Out of Stock</span>' : '';

                return '<div onclick="' + clickHandler + '" class="' + cardClasses + '">' +
                        '<div class="h-28 bg-slate-900 overflow-hidden relative flex items-center justify-center p-1">' +
                            imgHtml + varTag + oosBadge +
                        '</div>' +
                        '<div class="p-2.5 flex-1 flex flex-col justify-between">' +
                            '<div>' +
                                '<h3 class="font-bold text-xs text-white line-clamp-2 leading-snug">' + (p.name || 'Product') + '</h3>' +
                                '<p class="text-[10px] text-slate-400 mt-0.5">' + skuText + '</p>' +
                            '</div>' +
                            '<div class="mt-2.5 flex items-center justify-between border-t border-slate-700/60 pt-2">' +
                                '<span class="text-xs font-bold text-emerald-400 font-mono">' + currencySymbol + displayPrice + '</span>' +
                                '<span class="text-[10px] ' + stockClass + '">' + stockLabel + '</span>' +
                            '</div>' +
                        '</div>' +
                    '</div>';
            }).join('');
        }

        // Bug fix (#1): centralized out-of-stock check. For 'variable' products
        // this trusts the backend-aggregated stockStatus (see resolve_stock() in
        // REST_Server.php) rather than the parent's own — often meaningless —
        // stock fields.
        function isProductOutOfStock(p, variationId) {
            if (variationId) {
                var v = (p.variations || []).find(function(x) { return x.id === variationId; });
                if (!v) return true; // unknown variation — fail safe as blocked
                return v.stockStatus === 'outofstock'
                    || (v.stockQuantity !== undefined && v.stockQuantity !== null && v.stockQuantity <= 0 && v.stockStatus !== 'onbackorder');
            }
            if (p.stockStatus) {
                return p.stockStatus === 'outofstock';
            }
            // Fallback for any product missing stockStatus entirely (shouldn't
            // happen with the fixed API, but never silently allow the sale).
            return p.stockQuantity !== undefined && p.stockQuantity !== null && p.stockQuantity <= 0;
        }

        function handleOutOfStockClick(productId) {
            var prod = products.find(function(p) { return p.id === productId; });
            var name = prod ? prod.name : 'This item';
            alert('"' + name + '" is out of stock and cannot be added to the cart.\n\nPlease contact the inventory manager to restock or verify availability before selling this item.');
        }

        function handleProductClick(productId) {
            const prod = products.find(p => p.id === productId);
            if (!prod) return;

            if (prod.type === 'variable' && prod.variations && prod.variations.length > 0) {
                openVariationModal(prod);
            } else {
                const price = parseFloat(prod.price || prod.regularPrice || 0);
                addToCart(prod.id, prod.name, price, 0);
            }
        }

        function openVariationModal(prod) {
            document.getElementById('var-modal-title').innerText = prod.name + ' - Choose Variation';
            const list = document.getElementById('var-modal-list');
            if (!prod.variations || prod.variations.length === 0) {
                list.innerHTML = '<p class="text-slate-400 text-xs">No variations available</p>';
            } else {
                list.innerHTML = prod.variations.map(function(v) {
                    var vPrice = parseFloat(v.price || v.regularPrice || prod.price || 0);
                    var vPriceStr = isNaN(vPrice) ? '0.00' : vPrice.toFixed(2);
                    var safeName = (v.name || 'Option').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
                    var vImg = v.imageUrl ? '<img src="' + v.imageUrl + '" class="w-10 h-10 object-contain bg-slate-900 rounded-lg p-0.5 border border-slate-700" />' : '';
                    var vSku = v.sku ? 'SKU: ' + v.sku : 'No SKU';
                    var stockVal = (v.stockQuantity !== undefined && v.stockQuantity !== null) ? v.stockQuantity : null;
                    // Bug fix (#1): a specific variation can be out of stock even
                    // when the parent/other variations are available — block it
                    // individually rather than only gating at the parent level.
                    var vOutOfStock = isProductOutOfStock(prod, v.id);
                    var vRowClasses = vOutOfStock
                        ? 'p-3 bg-slate-900 border border-rose-900/60 rounded-xl flex items-center justify-between space-x-3 opacity-50 cursor-not-allowed'
                        : 'p-3 bg-slate-800 hover:bg-slate-700 border border-slate-700 rounded-xl cursor-pointer flex items-center justify-between transition space-x-3';
                    var vClickHandler = vOutOfStock
                        ? 'handleOutOfStockClick(' + v.id + ')'
                        : "selectVariation(" + prod.id + ", " + v.id + ", '" + safeName + "', " + vPrice + ")";
                    var vStockLabel = vOutOfStock
                        ? '<span class="text-rose-400 font-bold">Out of stock</span>'
                        : ('Stock: ' + (stockVal !== null ? stockVal : 'In stock'));

                    return '<div onclick="' + vClickHandler + '" class="' + vRowClasses + '">' +
                            '<div class="flex items-center space-x-3">' +
                                vImg +
                                '<div>' +
                                    '<p class="text-xs font-bold text-white">' + (v.name || 'Option') + '</p>' +
                                    '<p class="text-[10px] text-slate-400">' + vStockLabel + ' &bull; ' + vSku + '</p>' +
                                '</div>' +
                            '</div>' +
                            '<span class="text-xs font-bold text-emerald-400 font-mono">' + currencySymbol + vPriceStr + '</span>' +
                        '</div>';
                }).join('');
            }
            document.getElementById('variation-modal').classList.remove('hidden');
        }

        function closeVariationModal() {
            document.getElementById('variation-modal').classList.add('hidden');
        }

        function selectVariation(parentId, varId, varName, price) {
            closeVariationModal();
            addToCart(parentId, varName, price, varId);
        }

        // Bug fix (#1): addToCart() is the single choke point every "add" path
        // funnels through (grid click, variation modal). Re-verifying stock
        // here — not just at render/click time — means a stale grid can never
        // actually get an out-of-stock item into the cart; it's the hard stop.
        function addToCart(productId, name, price, variationId = 0) {
            const prod = products.find(p => p.id === productId);
            if (prod && isProductOutOfStock(prod, variationId || undefined)) {
                handleOutOfStockClick(variationId || productId);
                return;
            }

            const key = productId + '_' + variationId;
            const existing = cart.find(c => c.key === key);
            if (existing) {
                existing.quantity++;
            } else {
                cart.push({ key, productId, variationId, name, unitPrice: price, quantity: 1, discountAmount: 0 });
            }
            renderCart();
        }

        function updateCartQty(key, delta) {
            const item = cart.find(c => c.key === key);
            if (!item) return;
            item.quantity += delta;
            if (item.quantity <= 0) {
                cart = cart.filter(c => c.key !== key);
            }
            renderCart();
        }

        function setCartQty(key, newQty) {
            const qty = parseInt(newQty, 10);
            if (isNaN(qty) || qty <= 0) {
                cart = cart.filter(c => c.key !== key);
            } else {
                const item = cart.find(c => c.key === key);
                if (item) item.quantity = qty;
            }
            renderCart();
        }

        function removeCartItem(key) {
            cart = cart.filter(c => c.key !== key);
            renderCart();
        }

        function renderCart() {
            const container = document.getElementById('cart-items');
            const totalQty = cart.reduce((acc, c) => acc + c.quantity, 0);
            document.getElementById('cart-badge-count').innerText = totalQty + ' item' + (totalQty === 1 ? '' : 's');
            updateMobileCartToggle();

            if (cart.length === 0) {
                container.innerHTML = '<div class="text-center text-slate-500 text-xs py-16">Cart is empty</div>';
                document.getElementById('btn-checkout').disabled = true;
                document.getElementById('cart-subtotal').innerText = currencySymbol + '0.00';
                document.getElementById('cart-tax').innerText = currencySymbol + '0.00';
                document.getElementById('cart-total').innerText = currencySymbol + '0.00';
                document.getElementById('cart-discount-row').style.display = 'none';
                document.getElementById('cart-coupon-row').classList.add('hidden');
                return;
            }

            let subtotal     = 0;
            let totalDiscount = 0;

            container.innerHTML = cart.map(function(item) {
                var lineSubtotal = item.unitPrice * item.quantity;
                var discount     = item.discountAmount || 0;
                var lineTotal    = lineSubtotal - discount;
                subtotal      += lineSubtotal;
                totalDiscount += discount;

                // Low stock badge
                var stockBadge = '';
                var prod = products.find(p => p.id === item.productId);
                var stockQty = prod ? prod.stockQuantity : null;
                if (stockQty !== null && stockQty !== undefined && stockQty <= LOW_STOCK_THRESHOLD && stockQty > 0) {
                    stockBadge = '<span class="text-[9px] bg-amber-500/20 text-amber-400 border border-amber-500/30 px-1 rounded ml-1">Low: ' + stockQty + '</span>';
                } else if (stockQty !== null && stockQty === 0) {
                    stockBadge = '<span class="text-[9px] bg-rose-500/20 text-rose-400 border border-rose-500/30 px-1 rounded ml-1">Out</span>';
                }

                var discountBadge = discount > 0
                    ? '<span class="text-[9px] text-amber-400 ml-1">-' + currencySymbol + discount.toFixed(2) + '</span>'
                    : '';

                return '<div class="bg-slate-900 border border-slate-800 p-3 rounded-xl flex items-center justify-between text-xs space-x-2">' +
                        '<div class="truncate flex-1">' +
                            '<p class="font-bold text-white truncate">' + item.name + stockBadge + '</p>' +
                            '<p class="text-[10px] text-slate-400 font-mono">' + currencySymbol + item.unitPrice.toFixed(2) + ' ea' + discountBadge + '</p>' +
                        '</div>' +
                        '<div class="flex items-center space-x-1.5 shrink-0">' +
                            '<div class="flex items-center bg-slate-800 border border-slate-700 rounded-lg overflow-hidden">' +
                                '<button onclick="updateCartQty(\'' + item.key + '\', -1)" class="px-2.5 py-1.5 text-slate-300 hover:bg-slate-700 font-extrabold text-xs transition">-</button>' +
                                '<input type="number" min="1" value="' + item.quantity + '" onchange="setCartQty(\'' + item.key + '\', this.value)" class="w-10 text-center bg-transparent font-mono text-indigo-300 font-bold text-xs focus:outline-none" />' +
                                '<button onclick="updateCartQty(\'' + item.key + '\', 1)" class="px-2.5 py-1.5 text-slate-300 hover:bg-slate-700 font-extrabold text-xs transition">+</button>' +
                            '</div>' +
                            '<button onclick="openDiscountModal(\'' + item.key + '\')" class="p-1.5 text-amber-400 hover:text-amber-300 transition" title="Apply Discount">%</button>' +
                            '<span class="font-bold text-emerald-400 font-mono w-16 text-right">' + currencySymbol + lineTotal.toFixed(2) + '</span>' +
                            '<button onclick="removeCartItem(\'' + item.key + '\')" class="text-rose-400 hover:text-rose-300 p-1.5 font-bold text-sm" title="Remove Item">&times;</button>' +
                        '</div>' +
                    '</div>';
            }).join('');

            const couponDiscount = appliedOrderDiscount ? Math.min(appliedOrderDiscount.discountAmount, subtotal - totalDiscount) : 0;
            const netSubtotal = subtotal - totalDiscount - couponDiscount;
            const tax      = taxInclusive ? netSubtotal - (netSubtotal / (1 + taxRate)) : netSubtotal * taxRate;
            const grandTotal = taxInclusive ? netSubtotal : netSubtotal + tax;

            document.getElementById('cart-subtotal').innerText = currencySymbol + subtotal.toFixed(2);
            document.getElementById('cart-tax').innerText      = currencySymbol + tax.toFixed(2) + (taxRate > 0 ? ' (' + (taxRate * 100).toFixed(1) + '%)' : '');
            document.getElementById('cart-tax-label').innerText = taxInclusive ? 'Tax (Incl.):' : 'Tax (Est.):';
            document.getElementById('cart-total').innerText    = currencySymbol + grandTotal.toFixed(2);

            // Discount row
            if (totalDiscount > 0) {
                document.getElementById('cart-discount-row').style.display = 'flex';
                document.getElementById('cart-discount-total').innerText = '-' + currencySymbol + totalDiscount.toFixed(2);
            } else {
                document.getElementById('cart-discount-row').style.display = 'none';
            }

            // Coupon row
            if (appliedOrderDiscount && couponDiscount > 0) {
                document.getElementById('cart-coupon-row').classList.remove('hidden');
                document.getElementById('cart-coupon-label').innerText = appliedOrderDiscount.mode === 'coupon' ? ('Coupon (' + appliedOrderDiscount.code + '):') : 'Order Discount:';
                document.getElementById('cart-coupon-total').innerText = '-' + currencySymbol + couponDiscount.toFixed(2);
            } else {
                document.getElementById('cart-coupon-row').classList.add('hidden');
            }

            document.getElementById('btn-checkout').disabled = false;
            // Refresh dependent panels
            if (selectedPaymentMethod === 'cash') buildQuickAmounts();
            if (selectedPaymentMethod === 'transfer') updateTransferAmountDue();
            if (selectedPaymentMethod === 'split') updateSplitBalance();
        }

        // ---------------------------------------------------------------
        // Whole-order discount: coupon code, manual percentage, or manual
        // fixed amount. Coupon is self-authorizing (validated against a
        // real WooCommerce coupon); percent/fixed are arbitrary judgment
        // calls, so they route through requireManagerPin() first, same as
        // the per-item discount — the server independently re-checks the
        // override_wc_pos_prices capability regardless of the PIN result.
        // ---------------------------------------------------------------

        let orderDiscountMode = 'coupon'; // 'coupon' | 'percent' | 'fixed'

        function setOrderDiscountMode(mode) {
            orderDiscountMode = mode;
            document.getElementById('coupon-error').classList.add('hidden');

            ['coupon', 'percent', 'fixed'].forEach(m => {
                const btn = document.getElementById('odmode-btn-' + m);
                if (m === mode) {
                    btn.className = 'py-1.5 rounded-lg bg-indigo-600 text-white font-bold transition';
                } else {
                    btn.className = 'py-1.5 rounded-lg text-slate-400 hover:text-white font-bold transition';
                }
            });

            const codeInput  = document.getElementById('coupon-code-input');
            const valueInput = document.getElementById('order-discount-value-input');
            if (mode === 'coupon') {
                codeInput.classList.remove('hidden');
                valueInput.classList.add('hidden');
            } else {
                codeInput.classList.add('hidden');
                valueInput.classList.remove('hidden');
                valueInput.placeholder = mode === 'percent' ? 'e.g. 10' : 'e.g. 500';
            }
        }

        function applyOrderDiscount() {
            if (orderDiscountMode === 'coupon') {
                applyCouponCode();
            } else {
                applyManualOrderDiscount(orderDiscountMode);
            }
        }

        async function applyCouponCode() {
            const input = document.getElementById('coupon-code-input');
            const code = input.value.trim().toUpperCase();
            const errorBox = document.getElementById('coupon-error');
            errorBox.classList.add('hidden');

            if (!code) {
                errorBox.textContent = 'Enter a coupon code.';
                errorBox.classList.remove('hidden');
                return;
            }

            // Subtotal net of per-item discounts — matches what the server
            // checks the coupon's min/max spend against.
            const subtotal = cart.reduce((acc, c) => acc + (c.unitPrice * c.quantity) - (c.discountAmount || 0), 0);

            const btn = document.getElementById('order-discount-apply-btn');
            btn.disabled = true;
            try {
                const res = await fetch(restUrl + '/coupons/validate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                    body: JSON.stringify({ code, subtotal }),
                });
                const data = await res.json();

                if (!data.success) {
                    errorBox.textContent = data.message || 'This coupon could not be applied.';
                    errorBox.classList.remove('hidden');
                    return;
                }

                appliedOrderDiscount = { mode: 'coupon', code: data.code, value: 0, discountAmount: data.discountAmount };
                showOrderDiscountApplied(data.code + ' applied');
                input.value = '';
                renderCart();
            } catch (e) {
                errorBox.textContent = 'Network error while checking the coupon. Please try again.';
                errorBox.classList.remove('hidden');
            } finally {
                btn.disabled = false;
            }
        }

        function applyManualOrderDiscount(mode) {
            const errorBox = document.getElementById('coupon-error');
            errorBox.classList.add('hidden');

            const valueInput = document.getElementById('order-discount-value-input');
            const value = parseFloat(valueInput.value) || 0;

            if (value <= 0) {
                errorBox.textContent = 'Enter a value greater than zero.';
                errorBox.classList.remove('hidden');
                return;
            }
            if (mode === 'percent' && value > 100) {
                errorBox.textContent = 'Percentage cannot exceed 100.';
                errorBox.classList.remove('hidden');
                return;
            }

            const subtotal = cart.reduce((acc, c) => acc + (c.unitPrice * c.quantity) - (c.discountAmount || 0), 0);
            let discountAmount = mode === 'percent' ? subtotal * (value / 100) : value;
            discountAmount = Math.min(discountAmount, subtotal);

            // Same confirmation step as the per-item discount — an arbitrary
            // percentage/amount typed in at checkout is a manager-level
            // decision, unlike a coupon code, which authorizes itself.
            requireManagerPin(function() {
                appliedOrderDiscount = { mode, code: '', value, discountAmount };
                const label = mode === 'percent' ? (value + '% order discount applied') : (currencySymbol + value.toFixed(2) + ' order discount applied');
                showOrderDiscountApplied(label);
                valueInput.value = '';
                renderCart();
            });
        }

        function showOrderDiscountApplied(label) {
            document.getElementById('order-discount-input-row').classList.add('hidden');
            document.getElementById('order-discount-mode-tabs').classList.add('hidden');
            document.getElementById('coupon-applied-row').classList.remove('hidden');
            document.getElementById('coupon-applied-row').classList.add('flex');
            document.getElementById('coupon-applied-label').textContent = label;
        }

        function removeCouponCode() {
            appliedOrderDiscount = null;
            resetCouponUI();
            renderCart();
        }

        function resetCouponUI() {
            document.getElementById('order-discount-input-row').classList.remove('hidden');
            document.getElementById('order-discount-mode-tabs').classList.remove('hidden');
            document.getElementById('coupon-applied-row').classList.add('hidden');
            document.getElementById('coupon-applied-row').classList.remove('flex');
            document.getElementById('coupon-error').classList.add('hidden');
            const codeInput = document.getElementById('coupon-code-input');
            const valueInput = document.getElementById('order-discount-value-input');
            if (codeInput) codeInput.value = '';
            if (valueInput) valueInput.value = '';
            setOrderDiscountMode('coupon');
        }

        function clearCart() {
            cart = [];
            appliedOrderDiscount = null;
            resetCouponUI();
            renderCart();
        }

        // -----------------------------------------------------------------------
        // Discount modal
        // -----------------------------------------------------------------------

        function openDiscountModal(key) {
            const item = cart.find(c => c.key === key);
            if (!item) return;
            discountTargetKey = key;
            discountType = 'percent';
            document.getElementById('discount-item-name').textContent = item.name;
            document.getElementById('discount-value-input').value = '';
            document.getElementById('disc-prefix').textContent = '%';
            document.getElementById('disc-btn-pct').className = 'py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold transition';
            document.getElementById('disc-btn-flat').className = 'py-2 rounded-xl bg-slate-700 text-slate-300 text-xs font-bold transition';
            document.getElementById('discount-modal-title').textContent = 'Discount: ' + item.name;
            document.getElementById('discount-modal').classList.remove('hidden');
        }

        function closeDiscountModal() {
            document.getElementById('discount-modal').classList.add('hidden');
            discountTargetKey = null;
        }

        function setDiscountType(type) {
            discountType = type;
            document.getElementById('disc-prefix').textContent = type === 'percent' ? '%' : currencySymbol;
            document.getElementById('disc-btn-pct').className  = 'py-2 rounded-xl ' + (type === 'percent' ? 'bg-indigo-600 text-white' : 'bg-slate-700 text-slate-300') + ' text-xs font-bold transition';
            document.getElementById('disc-btn-flat').className = 'py-2 rounded-xl ' + (type === 'flat'    ? 'bg-indigo-600 text-white' : 'bg-slate-700 text-slate-300') + ' text-xs font-bold transition';
        }

        function applyItemDiscount() {
            const item = cart.find(c => c.key === discountTargetKey);
            if (!item) return closeDiscountModal();
            const val = parseFloat(document.getElementById('discount-value-input').value) || 0;
            const lineTotal = item.unitPrice * item.quantity;
            let discountAmt = discountType === 'percent' ? (lineTotal * val / 100) : val;
            discountAmt = Math.min(discountAmt, lineTotal); // can't discount more than line total
            discountAmt = Math.max(0, discountAmt);
            closeDiscountModal();

            if (discountAmt <= 0) {
                item.discountAmount = 0;
                renderCart();
                return;
            }

            // Bug fix: this previously applied the discount immediately with
            // no confirmation at all — the manager-PIN modal existed in the
            // markup but requireManagerPin() was never actually called from
            // here. The server independently enforces that the logged-in
            // account holds override_wc_pos_prices (or manage_woocommerce)
            // before accepting any discount; this PIN step is a deliberate-
            // intent confirmation on top of that, not a substitute for it.
            requireManagerPin(function() {
                item.discountAmount = discountAmt;
                renderCart();
            });
        }

        // -----------------------------------------------------------------------
        // Manager PIN gate
        // -----------------------------------------------------------------------

        function requireManagerPin(callback) {
            managerPinCallback = callback;
            document.getElementById('manager-pin-input').value = '';
            document.getElementById('manager-pin-modal').classList.remove('hidden');
            document.getElementById('manager-pin-input').focus();
        }

        function closeManagerPinModal() {
            document.getElementById('manager-pin-modal').classList.add('hidden');
            managerPinCallback = null;
        }

        async function verifyManagerPin() {
            const pin = document.getElementById('manager-pin-input').value.trim();
            if (!pin) return;
            try {
                const res  = await fetch(restUrl + '/pin/verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                    body: JSON.stringify({ pin })
                });
                const data = await res.json();
                if (data.success) {
                    // Bug fix: closeManagerPinModal() sets managerPinCallback
                    // to null — calling it BEFORE invoking the callback (the
                    // previous order here) meant the callback had already
                    // been wiped out by the time it was "invoked," so it
                    // silently never ran. This affected every PIN-gated
                    // discount: per-item discounts and the whole-order
                    // percent/fixed discount both use this same function —
                    // the PIN would verify successfully, the modal would
                    // close, and nothing would actually apply. Capture the
                    // callback in a local variable first, then close the
                    // modal, then call it.
                    const callback = managerPinCallback;
                    closeManagerPinModal();
                    if (callback) callback();
                } else {
                    document.getElementById('manager-pin-input').value = '';
                    alert(data.message || 'Incorrect manager PIN.');
                }
            } catch(e) {
                alert('Could not verify PIN. Check connection.');
            }
        }

        // ---------------------------------------------------------------
        // Change My PIN (self-service) — any cashier/manager can change
        // their own PIN anytime from here, not just once on first setup.
        // ---------------------------------------------------------------

        function openChangePinModal() {
            document.getElementById('change-pin-error').classList.add('hidden');
            document.getElementById('change-pin-current').value = '';
            document.getElementById('change-pin-new').value = '';
            document.getElementById('change-pin-confirm').value = '';
            document.getElementById('change-pin-modal').classList.remove('hidden');
        }

        function closeChangePinModal() {
            document.getElementById('change-pin-modal').classList.add('hidden');
        }

        async function submitChangePin() {
            const errorBox = document.getElementById('change-pin-error');
            errorBox.classList.add('hidden');

            const currentPin = document.getElementById('change-pin-current').value.trim();
            const newPin     = document.getElementById('change-pin-new').value.trim();
            const confirmPin = document.getElementById('change-pin-confirm').value.trim();

            if (!currentPin) {
                errorBox.textContent = 'Enter your current PIN.';
                errorBox.classList.remove('hidden');
                return;
            }
            if (!/^\d{4,8}$/.test(newPin)) {
                errorBox.textContent = 'New PIN must be 4 to 8 digits.';
                errorBox.classList.remove('hidden');
                return;
            }
            if (newPin !== confirmPin) {
                errorBox.textContent = 'New PIN and confirmation do not match.';
                errorBox.classList.remove('hidden');
                return;
            }
            if (newPin === currentPin) {
                errorBox.textContent = 'New PIN must be different from your current PIN.';
                errorBox.classList.remove('hidden');
                return;
            }

            const btn = document.getElementById('change-pin-submit-btn');
            btn.disabled = true;
            try {
                const res = await fetch(restUrl + '/pin/set', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                    body: JSON.stringify({ pin: newPin, currentPin }),
                });
                const data = await res.json();

                if (!data.success) {
                    errorBox.textContent = data.message || 'Could not update PIN.';
                    errorBox.classList.remove('hidden');
                    return;
                }

                closeChangePinModal();
                alert('PIN updated successfully.');
            } catch (e) {
                errorBox.textContent = 'Network error. Please check your connection and try again.';
                errorBox.classList.remove('hidden');
            } finally {
                btn.disabled = false;
            }
        }

        function setPaymentMethod(method) {
            selectedPaymentMethod = method;
            ['cash', 'card', 'transfer', 'split'].forEach(m => {
                const btn = document.getElementById('pay-btn-' + m);
                if (btn) {
                    btn.classList.toggle('bg-indigo-600', m === method);
                    btn.classList.toggle('text-white', m === method);
                    btn.classList.toggle('text-slate-400', m !== method);
                }
            });
            // Show relevant payment input panel
            const cashPanel     = document.getElementById('cash-calc-panel');
            const transferPanel = document.getElementById('transfer-panel');
            const splitPanel    = document.getElementById('split-payment-panel');
            if (cashPanel)     cashPanel.classList.toggle('hidden',     method !== 'cash');
            if (transferPanel) transferPanel.classList.toggle('hidden', method !== 'transfer');
            if (splitPanel)    splitPanel.classList.toggle('hidden',    method !== 'split');
            if (method === 'cash') buildQuickAmounts();
            if (method === 'transfer') updateTransferAmountDue();
            if (method === 'split') updateSplitBalance();
        }

        // Delivery feature: toggles the Pickup/Delivery buttons and shows/
        // hides the delivery address field. This was previously missing
        // entirely — the buttons existed in the markup but called a
        // function that was never defined, so clicking them did nothing.
        function setFulfillmentType(type) {
            currentFulfillmentType = type;
            ['pickup', 'delivery'].forEach(t => {
                const btn = document.getElementById('fulfill-btn-' + t);
                if (btn) {
                    btn.classList.toggle('bg-indigo-600', t === type);
                    btn.classList.toggle('text-white', t === type);
                    btn.classList.toggle('text-slate-400', t !== type);
                }
            });
            const addressRow = document.getElementById('delivery-address-row');
            if (addressRow) addressRow.classList.toggle('hidden', type !== 'delivery');
        }

        function updateTransferAmountDue() {
            const grandTotal = parseFloat(document.getElementById('cart-total').innerText.replace(/[^0-9.]/g, '')) || 0;
            const el = document.getElementById('transfer-amount-due');
            if (el) el.innerText = currencySymbol + grandTotal.toFixed(2);
        }

        function buildQuickAmounts() {
            const grandTotal = parseFloat(document.getElementById('cart-total').innerText.replace(/[^0-9.]/g, '')) || 0;
            const container  = document.getElementById('quick-amounts');
            if (!container || grandTotal <= 0) return;
            // Round up to sensible denominations
            const denoms = [5, 10, 20, 50, 100, 200, 500].filter(d => d >= grandTotal);
            const amounts = [grandTotal, ...denoms.slice(0, 3)];
            const unique  = [...new Set(amounts.map(a => Math.ceil(a / 5) * 5 > a ? Math.ceil(a / 5) * 5 : a))].slice(0, 4);
            container.innerHTML = unique.map(a =>
                '<button onclick="setTendered(' + a + ')" class="py-1 rounded-lg bg-slate-700 hover:bg-slate-600 text-slate-200 text-[11px] font-mono transition">' + currencySymbol + a.toFixed(2) + '</button>'
            ).join('');
        }

        function setTendered(amount) {
            const el = document.getElementById('cash-tendered');
            if (el) { el.value = amount.toFixed(2); updateChangeDue(); }
        }

        function updateChangeDue() {
            const grandTotal = parseFloat(document.getElementById('cart-total').innerText.replace(/[^0-9.]/g, '')) || 0;
            const tendered   = parseFloat(document.getElementById('cash-tendered').value) || 0;
            const change     = Math.max(0, tendered - grandTotal);
            document.getElementById('change-due').innerText = currencySymbol + change.toFixed(2);
            document.getElementById('change-due').classList.toggle('text-rose-400', tendered > 0 && tendered < grandTotal);
            document.getElementById('change-due').classList.toggle('text-emerald-400', tendered === 0 || tendered >= grandTotal);
        }

        function updateSplitBalance() {
            const grandTotal = parseFloat(document.getElementById('cart-total').innerText.replace(/[^0-9.]/g, '')) || 0;
            const cash     = parseFloat(document.getElementById('split-cash').value) || 0;
            const card     = parseFloat(document.getElementById('split-card').value) || 0;
            const transfer = parseFloat(document.getElementById('split-transfer').value) || 0;
            const remaining = grandTotal - cash - card - transfer;
            const el = document.getElementById('split-remaining');
            if (el) {
                el.innerText = currencySymbol + Math.abs(remaining).toFixed(2) + (remaining < 0 ? ' (over)' : '');
                el.className = 'font-mono font-bold ' + (Math.abs(remaining) < 0.01 ? 'text-emerald-400' : remaining < 0 ? 'text-rose-400' : 'text-amber-400');
            }
        }

        // Customer Modal
        function openCustomerModal() {
            closeMobileSidebarOverlay();
            document.getElementById('customer-modal').classList.remove('hidden');
        }

        function closeCustomerModal() {
            document.getElementById('customer-modal').classList.add('hidden');
        }

        function selectCustomer(cust) {
            selectedCustomer = cust;
            if (cust) {
                document.getElementById('current-customer-name').innerText = cust.name;
                document.getElementById('current-customer-phone').innerText = cust.phone || cust.email;
            } else {
                document.getElementById('current-customer-name').innerText = 'Guest / Walk-In Customer';
                document.getElementById('current-customer-phone').innerText = 'No account assigned';
            }
            closeCustomerModal();
        }

        function selectCustomerByIndex(idx) {
            if (searchCustomerResults && searchCustomerResults[idx]) {
                selectCustomer(searchCustomerResults[idx]);
            }
        }

        async function searchCustomers() {
            const qInput = document.getElementById('cust-search-input');
            const q = qInput ? qInput.value.toLowerCase().trim() : '';
            if (q.length < 1) return;

            try {
                const res = await fetch(restUrl + '/customers?s=' + encodeURIComponent(q), { headers: { 'X-WP-Nonce': restNonce } });
                if (res.ok) {
                    searchCustomerResults = await res.json();
                } else {
                    searchCustomerResults = [
                        { id: 1, name: 'John Doe', email: 'john@example.com', phone: '+1-555-0192' },
                        { id: 2, name: 'Jane Smith', email: 'jane@example.com', phone: '+1-555-0183' },
                        { id: 3, name: 'Michael Brown', email: 'michael@example.com', phone: '+1-555-0144' }
                    ].filter(c => c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q));
                }
            } catch (e) {
                searchCustomerResults = [
                    { id: 1, name: 'John Doe', email: 'john@example.com', phone: '+1-555-0192' },
                    { id: 2, name: 'Jane Smith', email: 'jane@example.com', phone: '+1-555-0183' },
                    { id: 3, name: 'Michael Brown', email: 'michael@example.com', phone: '+1-555-0144' }
                ].filter(c => c.name.toLowerCase().includes(q) || c.email.toLowerCase().includes(q));
            }

            const resultsDiv = document.getElementById('cust-search-results');
            if (!searchCustomerResults || searchCustomerResults.length === 0) {
                resultsDiv.innerHTML = '<div class="text-slate-400 text-[11px] p-2">No matching customers found</div>';
                return;
            }

            resultsDiv.innerHTML = searchCustomerResults.map(function(c, idx) {
                var contact = c.email || c.phone || 'No contact';
                return '<div onclick="selectCustomerByIndex(' + idx + ')" class="p-2 bg-slate-800 hover:bg-slate-700 rounded-lg cursor-pointer flex justify-between items-center transition">' +
                        '<div>' +
                            '<p class="font-bold text-white">' + c.name + '</p>' +
                            '<p class="text-[10px] text-slate-400">' + contact + '</p>' +
                        '</div>' +
                        '<span class="text-indigo-400 text-[10px] font-bold">Select</span>' +
                    '</div>';
            }).join('');
        }

        async function addNewCustomer() {
            const name = document.getElementById('new-cust-name').value;
            const email = document.getElementById('new-cust-email').value;
            const phone = document.getElementById('new-cust-phone').value;
            if (!name) return alert('Name is required');

            try {
                const res = await fetch(restUrl + '/customers', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                    body: JSON.stringify({ name, email, phone })
                });
                const data = await res.json();
                if (data.success) {
                    selectCustomer(data.customer);
                } else {
                    selectCustomer({ id: Date.now(), name, email, phone });
                }
            } catch (e) {
                selectCustomer({ id: Date.now(), name, email, phone });
            }
        }

        // Parked Carts
        function parkCurrentCart() {
            if (cart.length === 0) return alert('Cart is empty');
            parkedCarts.push({ id: Date.now(), items: [...cart], customer: selectedCustomer, time: new Date().toLocaleTimeString() });
            localStorage.setItem('wc_pos_parked_carts', JSON.stringify(parkedCarts));
            clearCart();
            updateParkedBadge();
            alert('Cart parked successfully!');
        }

        function updateParkedBadge() {
            const badge = document.getElementById('parked-count-badge');
            if (badge) {
                badge.innerText = parkedCarts.length;
                badge.classList.toggle('hidden', parkedCarts.length === 0);
            }
        }

        function renderParkedCarts() {
            const list = document.getElementById('parked-carts-list');
            if (!list) return;
            if (parkedCarts.length === 0) {
                list.innerHTML = '<div class="text-center text-slate-500 text-xs py-12">No parked carts stored</div>';
                return;
            }
            list.innerHTML = parkedCarts.map(function(p, idx) {
                var custName = p.customer ? p.customer.name : 'Guest';
                return '<div class="bg-slate-800 border border-slate-700 p-4 rounded-xl flex items-center justify-between">' +
                        '<div>' +
                            '<p class="font-bold text-sm text-white">Parked Cart #' + (idx + 1) + ' (' + p.items.length + ' items)</p>' +
                            '<p class="text-xs text-slate-400">Customer: ' + custName + ' &bull; Time: ' + p.time + '</p>' +
                        '</div>' +
                        '<button onclick="resumeParkedCart(' + p.id + ')" class="bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition">' +
                            'Resume Cart' +
                        '</button>' +
                    '</div>';
            }).join('');
        }

        function resumeParkedCart(id) {
            const p = parkedCarts.find(c => c.id === id);
            if (!p) return;
            cart = [...p.items];
            selectCustomer(p.customer);
            parkedCarts = parkedCarts.filter(c => c.id !== id);
            localStorage.setItem('wc_pos_parked_carts', JSON.stringify(parkedCarts));
            updateParkedBadge();
            switchTab('register');
            renderCart();
        }

        // Order History
        async function loadOrderHistory() {
            const container = document.getElementById('history-orders-list');
            if (!container) return;
            try {
                const res = await fetch(restUrl + '/orders', { headers: { 'X-WP-Nonce': restNonce } });
                if (res.ok) {
                    const orders = await res.json();
                    if (orders.length === 0) {
                        container.innerHTML = '<div class="text-center text-slate-500 text-xs py-12">No past POS sales found</div>';
                        return;
                    }
                    container.innerHTML = orders.map(function(o) {
                        var num = o.orderNumber || o.id;
                        var tot = parseFloat(o.total || 0).toFixed(2);
                        var dt = o.dateCreated || 'Today';
                        var cashier = o.cashierName || 'Staff';
                        return '<div class="bg-slate-800 border border-slate-700 p-3.5 rounded-xl flex items-center justify-between text-xs">' +
                                '<div>' +
                                    '<p class="font-bold text-white">Order #' + num + ' &bull; ' + currencySymbol + tot + '</p>' +
                                    '<p class="text-[10px] text-slate-400">Date: ' + dt + ' &bull; Cashier: ' + cashier + '</p>' +
                                '</div>' +
                                '<button onclick="reprintReceipt(' + o.id + ')" class="bg-slate-700 hover:bg-slate-600 text-slate-200 px-3 py-1.5 rounded-lg border border-slate-600 transition">' +
                                    'Reprint Receipt' +
                                '</button>' +
                            '</div>';
                    }).join('');
                } else {
                    throw new Error('API');
                }
            } catch(e) {
                container.innerHTML = '<div class="bg-slate-800 border border-slate-700 p-3.5 rounded-xl flex items-center justify-between text-xs">' +
                        '<div>' +
                            '<p class="font-bold text-white">Order #1001 &bull; ' + currencySymbol + '145.00</p>' +
                            '<p class="text-[10px] text-slate-400">Date: Today &bull; Cashier: Sarah Jenkins</p>' +
                        '</div>' +
                        '<button onclick="alert(\'Printing thermal receipt for Order #1001\')" class="bg-slate-700 hover:bg-slate-600 text-slate-200 px-3 py-1.5 rounded-lg border border-slate-600 transition">' +
                            'Reprint Receipt' +
                        '</button>' +
                    '</div>';
            }
        }

        // Bug fix: this button previously did nothing but show a generic
        // browser alert ("Printing thermal receipt for Order #X") — it never
        // fetched the real order or touched the actual receipt template at
        // all. Now fetches the order's full detail and reuses the exact
        // same buildReceipt()/print flow the original checkout receipt uses.
        async function reprintReceipt(orderId) {
            try {
                const res = await fetch(restUrl + '/orders/' + orderId, { headers: { 'X-WP-Nonce': restNonce } });
                const data = await res.json();

                if (!data.success) {
                    alert(data.message || 'Could not load this order for reprinting.');
                    return;
                }

                buildReceipt({
                    orderId:      data.orderId,
                    items:        data.items,
                    subtotal:     data.subtotal,
                    totalDiscount: data.totalDiscount,
                    tax:          data.tax,
                    grandTotal:   data.grandTotal,
                    payments:     data.payments,
                    changeDue:    data.changeDue,
                    cashierName:  data.cashierName,
                });

                window.print();
                const receiptEl = document.getElementById('printable-receipt');
                if (receiptEl) {
                    receiptEl.classList.add('hidden');
                    receiptEl.style.display = '';
                }
            } catch (e) {
                alert('Network error while loading this order. Please try again.');
            }
        }

        // Process Checkout
        async function processCheckout() {
            if (cart.length === 0) return;

            // Bug fix: shift status must be enforced before a sale, not just
            // tracked cosmetically. The server independently rejects any
            // order for a register with no open shift; this check just gives
            // a clearer message before the cashier builds out totals/payment
            // rather than after a failed submission.
            if (currentShiftStatus !== 'open') {
                alert('This register does not have an open shift. Open a shift (see the header indicator) before processing sales.');
                return;
            }

            // Delivery feature: an address is required if Delivery is
            // selected — otherwise the order would go out with no way to
            // actually deliver it.
            const deliveryAddressInput = document.getElementById('delivery-address-input');
            const deliveryAddress = deliveryAddressInput ? deliveryAddressInput.value.trim() : '';
            if (currentFulfillmentType === 'delivery' && !deliveryAddress) {
                alert('Enter a delivery address, or switch to Pickup.');
                return;
            }

            const subtotal      = cart.reduce((acc, c) => acc + (c.unitPrice * c.quantity), 0);
            const totalDiscount = cart.reduce((acc, c) => acc + (c.discountAmount || 0), 0);
            // Bug fix: this previously ignored appliedOrderDiscount entirely, so the
            // amount the cashier collected (tendered/change/split validation)
            // would still reflect the pre-coupon total even after a coupon
            // was successfully applied in the cart summary above.
            const couponDiscount = appliedOrderDiscount ? Math.min(appliedOrderDiscount.discountAmount, subtotal - totalDiscount) : 0;
            const netSubtotal   = subtotal - totalDiscount - couponDiscount;
            const tax           = taxInclusive ? netSubtotal - (netSubtotal / (1 + taxRate)) : netSubtotal * taxRate;
            const grandTotal    = taxInclusive ? netSubtotal : netSubtotal + tax;

            // Build payments array
            let payments = [];
            let changeDue = 0;
            if (selectedPaymentMethod === 'split') {
                const cashAmt     = parseFloat(document.getElementById('split-cash').value) || 0;
                const cardAmt     = parseFloat(document.getElementById('split-card').value) || 0;
                const transferAmt = parseFloat(document.getElementById('split-transfer').value) || 0;
                if (Math.abs(cashAmt + cardAmt + transferAmt - grandTotal) > 0.01) {
                    alert('Split amounts (' + currencySymbol + (cashAmt + cardAmt + transferAmt).toFixed(2) + ') must equal the total (' + currencySymbol + grandTotal.toFixed(2) + ').');
                    return;
                }
                if (cashAmt > 0) payments.push({ method: 'cash', amount: cashAmt });
                if (cardAmt > 0) payments.push({ method: 'card', amount: cardAmt });
                if (transferAmt > 0) payments.push({ method: 'transfer', amount: transferAmt });
            } else if (selectedPaymentMethod === 'cash') {
                const tendered = parseFloat(document.getElementById('cash-tendered').value) || grandTotal;
                changeDue = Math.max(0, tendered - grandTotal);
                payments  = [{ method: 'cash', amount: grandTotal }];
            } else {
                payments = [{ method: selectedPaymentMethod, amount: grandTotal }];
            }

            const cashierName = '<?php echo esc_js( $user->display_name ); ?>';
            const payload = {
                id:             'POS-' + Date.now(),
                idempotencyKey: 'POS-' + Date.now() + '-' + Math.random().toString(36).slice(2, 9),
                registerId:     currentRegisterId || 'REG-MAIN',
                branchId:       currentBranchId || 'default',
                cashierId:      <?php echo intval( $user->ID ); ?>,
                cashierName,
                customerId:     selectedCustomer ? selectedCustomer.id : 0,
                orderNote:      (document.getElementById('pos-order-note').value || '').trim(),
                orderDiscount:  appliedOrderDiscount ? { mode: appliedOrderDiscount.mode, code: appliedOrderDiscount.code || '', value: appliedOrderDiscount.value || 0 } : null,
                fulfillmentType: currentFulfillmentType,
                deliveryAddress: currentFulfillmentType === 'delivery' ? deliveryAddress : '',
                items: cart.map(c => ({
                    productId:      c.productId,
                    variationId:    c.variationId,
                    quantity:       c.quantity,
                    unitPrice:      c.unitPrice,
                    discountTotal:  c.discountAmount || 0,
                })),
                payments,
            };

            const btn = document.getElementById('btn-checkout');
            btn.disabled = true;
            btn.querySelector('span').textContent = 'Processing...';

            try {
                const res  = await fetch(restUrl + '/orders', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': restNonce },
                    body: JSON.stringify(payload),
                });
                const data = await res.json();

                if (data.success) {
                    // Build and print receipt
                    buildReceipt({
                        orderId:       data.orderId,
                        items:         payload.items.map((it, idx) => ({ ...it, name: cart[idx].name, sku: cart[idx].sku || '' })),
                        subtotal, totalDiscount, tax, grandTotal, payments, changeDue, cashierName,
                    });
                    window.print();
                    // Re-hide receipt element after printing
                    const receiptEl = document.getElementById('printable-receipt');
                    if (receiptEl) {
                        receiptEl.classList.add('hidden');
                        receiptEl.style.display = '';
                    }
                    clearCart();
                    selectCustomer(null);
                    document.getElementById('pos-order-note').value = '';
                    if (deliveryAddressInput) deliveryAddressInput.value = '';
                    setFulfillmentType('pickup');
                    fetchProducts();
                    // Responsive fix: on mobile, the cart is a full-screen
                    // view — after a completed sale, return the cashier to
                    // the product grid automatically rather than leaving
                    // them stuck on an empty cart screen.
                    closeMobileCart();
                } else {
                    alert('Sale failed: ' + (data.message || 'Unknown error. Please try again.'));
                }
            } catch(e) {
                alert('Network error. The sale could not be submitted. Please check your connection and retry.');
            } finally {
                btn.disabled = cart.length === 0;
                btn.querySelector('span').textContent = 'COMPLETE SALE & PRINT RECEIPT';
            }
        }

        const pSearch = document.getElementById('product-search');
        // Note: the search input's oninput attribute already calls
        // onProductSearchInput() directly — no separate listener needed
        // (a duplicate one here previously called the old client-side-only
        // renderProducts() redundantly on every keystroke).
        initTheme();
        updateParkedBadge();
        updateBranchRegisterLabel();
        // Bug fix: fetch the branches list on every load (not only when the
        // picker is opened) so the header can resolve a real branch NAME
        // instead of falling back to the raw ID — this was the visible
        // symptom of a deeper problem: a remembered branch/register that no
        // longer matches what's actually selected.
        loadBranchesList();
        loadConfig().then(() => {
            fetchCategories();
            fetchProducts();
            // Multi-branch feature: nudge the cashier to pick a branch/register
            // on first use of this terminal. Not forced (no blocking overlay
            // on every load) — the header indicator stays visible and clicking
            // it always re-opens the picker if they skip this or need to switch.
            if (!currentBranchId || !currentRegisterId) {
                openBranchPicker();
            } else {
                refreshShiftStatus();
            }
        });

        // -----------------------------------------------------------------------
        // Keyboard shortcuts
        // -----------------------------------------------------------------------
        document.addEventListener('keydown', function(e) {
            // Don't fire when focus is inside an input / textarea
            const tag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
            const inInput = tag === 'input' || tag === 'textarea' || tag === 'select';

            if (e.key === 'F1') { e.preventDefault(); switchTab('register'); document.getElementById('product-search').focus(); }
            if (e.key === 'F2') { e.preventDefault(); parkCurrentCart(); }
            if (e.key === 'F9') { e.preventDefault(); if (!document.getElementById('btn-checkout').disabled) processCheckout(); }
            if (e.key === 'Escape') {
                // Close the topmost visible modal
                const modals = ['discount-modal', 'manager-pin-modal', 'variation-modal', 'customer-modal', 'shift-modal-overlay', 'change-pin-modal'];
                for (const id of modals) {
                    const el = document.getElementById(id);
                    if (el && !el.classList.contains('hidden')) { el.classList.add('hidden'); break; }
                }
            }
            // / key focuses search when not already in an input
            if (e.key === '/' && !inInput) {
                e.preventDefault();
                const s = document.getElementById('product-search');
                if (s) { switchTab('register'); s.focus(); s.select(); }
            }
        });
    </script>
</body>
</html>
