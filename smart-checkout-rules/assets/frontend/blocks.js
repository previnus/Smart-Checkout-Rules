(() => {
    const { select, dispatch, subscribe } = window.wp.data;
    const cart = select('wc/store/cart');
    const notices = dispatch('core/notices');
    if (!cart || !notices || !cart.getCartData) return;

    const contexts = ['wc/cart', 'wc/checkout'];
    let shown = [];
    const sync = () => {
        const data = cart.getCartData();
        const messages = data?.extensions?.['smart-checkout-rules']?.messages || [];
        if (messages.length === shown.length && messages.every((message, index) => message === shown[index])) return;
        const previous = shown;
        shown = messages.slice();
        contexts.forEach((context) => {
            previous.forEach((_, index) => notices.removeNotice('scr-preview-' + index, context));
            shown.forEach((message, index) => notices.createInfoNotice(message, {
                id: 'scr-preview-' + index, context, isDismissible: false,
            }));
        });
    };
    subscribe(sync, 'wc/store/cart');
    sync();
})();
