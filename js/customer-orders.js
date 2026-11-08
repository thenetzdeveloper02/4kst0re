(() => {
    const widget = document.querySelector("[data-customer-orders-widget]");
    if (!widget) {
        return;
    }

    const trigger = widget.querySelector(".customer-orders-trigger");
    const panel = widget.querySelector("[data-orders-panel]");
    const list = widget.querySelector("[data-orders-list]");
    const count = widget.querySelector("[data-orders-count]");
    const summary = widget.querySelector("[data-orders-summary]");
    const endpoint = widget.dataset.endpoint;
    const csrfToken = widget.dataset.csrf;
    if (!trigger || !panel || !list || !count || !summary || !endpoint) {
        return;
    }

    const setMessage = (message, isError = false) => {
        const paragraph = document.createElement("p");
        paragraph.className = `notification-empty${isError ? " notification-error" : ""}`;
        paragraph.textContent = message;
        list.replaceChildren(paragraph);
    };

    const statusLabel = (status) => ({
        Pending: "Order pending",
        Paid: "Payment verified",
        Processing: "Processing",
        Completed: "Completed",
        Cancelled: "Cancelled"
    }[status] || status);

    const paymentLabel = (status) => ({
        pending: "Payment pending",
        verified: "Payment confirmed",
        rejected: "Payment needs attention",
        partially_refunded: "Partially refunded",
        refunded: "Refunded"
    }[status] || status);

    const renderOrders = (orders, pendingCount, currency) => {
        count.textContent = pendingCount > 99 ? "99+" : String(pendingCount);
        count.hidden = pendingCount === 0;
        trigger.setAttribute("aria-label", pendingCount ? `My orders, ${pendingCount} in progress` : "My orders");
        summary.textContent = orders.length ? `${orders.length} recent purchase${orders.length === 1 ? "" : "s"}` : "No purchases yet";
        list.replaceChildren();

        if (!orders.length) {
            setMessage("Your purchases will appear here after you place an order.");
            return;
        }

        orders.forEach((order) => {
            const item = document.createElement("article");
            item.className = "customer-order-item";

            const image = document.createElement("img");
            image.className = "customer-order-image";
            image.src = order.product_image;
            image.alt = "";
            image.loading = "lazy";
            image.addEventListener("error", () => {
                image.src = "../home/logo/logome.png";
            }, { once: true });

            const details = document.createElement("div");
            details.className = "customer-order-details";
            const name = document.createElement("strong");
            name.className = "customer-order-name";
            name.textContent = order.product_name;

            const reference = document.createElement("span");
            reference.textContent = order.reference ? `Order ${order.reference}` : `Order #${order.id}`;

            const amount = document.createElement("span");
            const total = Math.max(0, order.unit_price * order.quantity - order.discount_amount);
            amount.textContent = `Qty ${order.quantity} · ${currency}${total.toFixed(2)}`;

            const status = document.createElement("span");
            status.className = `customer-order-status${order.status === "Completed" ? " is-success" : order.status === "Cancelled" ? " is-cancelled" : ""}`;
            status.textContent = statusLabel(order.status);

            const payment = document.createElement("span");
            payment.className = "customer-order-payment";
            payment.textContent = paymentLabel(order.payment_status);

            const date = document.createElement("time");
            date.dateTime = order.created_at;
            date.textContent = order.created_at;

            details.append(name, reference, amount, status, payment, date);

            if (order.admin_delivered_at) {
                const adminDelivery = document.createElement("span");
                adminDelivery.className = "customer-order-admin-delivery";
                adminDelivery.textContent = `រដ្ឋបាលបានបញ្ជាក់ថាឥវ៉ាន់ដល់ទីតាំង៖ ${order.location} · ${order.admin_delivered_at}`;
                details.append(adminDelivery);
            }

            if (order.delivery_status === "success" || order.received_at) {
                const received = document.createElement("span");
                received.className = "customer-order-received";
                received.textContent = "បានបញ្ជាក់ថាទទួលបានឥវ៉ាន់";
                details.append(received);
            } else if (order.delivery_status === "failed") {
                const failed = document.createElement("span");
                failed.className = "customer-order-delivery-failed";
                failed.textContent = "មិនបានទទួលឥវ៉ាន់";

                const contactLinks = createContactLinks(order, false);
                details.append(failed, contactLinks);
            } else if (order.status !== "Cancelled") {
                const actions = document.createElement("div");
                actions.className = "customer-order-actions";

                const receivedButton = document.createElement("button");
                receivedButton.className = "customer-order-action customer-order-received-button";
                receivedButton.type = "button";
                receivedButton.textContent = "ទទួលបានឥវ៉ាន់";

                const missingButton = document.createElement("button");
                missingButton.className = "customer-order-action customer-order-missing-button";
                missingButton.type = "button";
                missingButton.textContent = "មិនបានទទួល";

                const actionError = document.createElement("span");
                actionError.className = "customer-order-action-error";
                actionError.setAttribute("role", "status");
                actionError.setAttribute("aria-live", "polite");

                const submitDeliveryStatus = async (action, button) => {
                    button.disabled = true;
                    actionError.textContent = "";
                    try {
                        const response = await fetch(endpoint, {
                            method: "POST",
                            credentials: "same-origin",
                            headers: {
                                "Content-Type": "application/json",
                                "X-CSRF-Token": csrfToken || ""
                            },
                            body: JSON.stringify({ action, order_id: order.id })
                        });
                        const result = await response.json();
                        if (!response.ok || result.success !== true) {
                            throw new Error(result.message || "Could not update delivery status.");
                        }
                        await loadOrders();
                    } catch (error) {
                        button.disabled = false;
                        actionError.textContent = error instanceof Error ? error.message : "Could not update delivery status.";
                    }
                };

                receivedButton.addEventListener("click", () => submitDeliveryStatus("confirm_received", receivedButton));
                missingButton.addEventListener("click", () => submitDeliveryStatus("report_not_received", missingButton));

                actions.append(receivedButton, missingButton);
                details.append(actions, actionError);
            }

            item.append(image, details);
            list.append(item);
        });
    };

    const createContactLinks = (order, hidden) => {
        const contactLinks = document.createElement("div");
        contactLinks.className = "customer-order-contact-links";
        contactLinks.hidden = hidden;
        const orderLabel = order.reference || `#${order.id}`;
        const telegram = document.createElement("a");
        telegram.href = `https://t.me/nate1234567890?text=${encodeURIComponent(`I have not received order ${orderLabel} (${order.product_name}).`)}`;
        telegram.target = "_blank";
        telegram.rel = "noopener noreferrer";
        telegram.textContent = "ទាក់ទងតាម Telegram";

        const phone = document.createElement("a");
        phone.href = "tel:+85560269308";
        phone.textContent = "ហៅទូរស័ព្ទ +855 60 269 308";
        contactLinks.append(telegram, phone);
        return contactLinks;
    };

    const loadOrders = async () => {
        try {
            const response = await fetch(endpoint, { credentials: "same-origin", cache: "no-store" });
            const data = await response.json();
            if (!response.ok || data.success !== true) {
                throw new Error(data.message || "Could not load your orders.");
            }
            renderOrders(
                Array.isArray(data.orders) ? data.orders : [],
                Number(data.pending_count) || 0,
                typeof data.currency === "string" ? data.currency : "$"
            );
        } catch (error) {
            setMessage(error.message || "Could not load your orders.", true);
        }
    };

    trigger.addEventListener("click", async () => {
        const opening = panel.hidden;
        panel.hidden = !opening;
        trigger.setAttribute("aria-expanded", String(opening));
        if (opening) {
            await loadOrders();
        }
    });

    document.addEventListener("click", (event) => {
        if (!widget.contains(event.target) && !panel.hidden) {
            panel.hidden = true;
            trigger.setAttribute("aria-expanded", "false");
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && !panel.hidden) {
            panel.hidden = true;
            trigger.setAttribute("aria-expanded", "false");
            trigger.focus();
        }
    });

    loadOrders();
    window.setInterval(loadOrders, 60000);
})();
