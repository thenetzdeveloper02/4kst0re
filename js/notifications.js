(() => {
    const widgets = document.querySelectorAll("[data-notification-widget]");

    widgets.forEach((widget) => {
        const trigger = widget.querySelector(".notification-trigger");
        const panel = widget.querySelector("[data-notification-panel]");
        const list = widget.querySelector("[data-notification-list]");
        const count = widget.querySelector("[data-notification-count]");
        const markAll = widget.querySelector("[data-notification-mark-all]");
        const endpoint = widget.dataset.endpoint;
        const role = widget.dataset.role;
        const csrfToken = widget.dataset.csrf;

        if (!trigger || !panel || !list || !count || !markAll || !endpoint || !role) {
            return;
        }

        const renderCount = (value) => {
            const unread = Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;
            count.textContent = unread > 99 ? "99+" : String(unread);
            count.hidden = unread === 0;
            trigger.setAttribute("aria-label", unread ? `Notifications, ${unread} unread` : "Notifications");
        };

        const markRead = async (id) => {
            const response = await fetch(endpoint, {
                method: "POST",
                credentials: "same-origin",
                headers: {
                    "Content-Type": "application/json",
                    "X-CSRF-Token": csrfToken || ""
                },
                body: JSON.stringify({ role, action: "mark_read", id })
            });
            const result = await response.json();
            if (!response.ok || !result.success) {
                throw new Error(result.error || "Could not update notification.");
            }
            return result;
        };

        const renderNotifications = (notifications) => {
            list.replaceChildren();
            if (!notifications.length) {
                const empty = document.createElement("p");
                empty.className = "notification-empty";
                empty.textContent = "You’re all caught up.";
                list.append(empty);
                markAll.hidden = true;
                return;
            }

            markAll.hidden = false;
            notifications.forEach((notification) => {
                const item = document.createElement("article");
                item.className = `notification-item${notification.read ? "" : " is-unread"}`;
                const title = document.createElement("strong");
                title.className = "notification-item-title";
                title.textContent = notification.title;
                const message = document.createElement("span");
                message.className = "notification-item-message";
                message.textContent = notification.message;
                const date = document.createElement("time");
                date.className = "notification-item-date";
                date.dateTime = notification.created_at;
                date.textContent = notification.created_at;
                item.append(title, message, date);

                if (!notification.read) {
                    item.dataset.notificationId = String(notification.id);
                    item.tabIndex = 0;
                    item.setAttribute("role", "button");
                    item.setAttribute("aria-label", `Mark ${notification.title} as read`);
                }

                const openNotification = async () => {
                    if (notification.read) {
                        if (notification.target_url) {
                            window.location.assign(notification.target_url);
                        }
                        return;
                    }
                    try {
                        await markRead(notification.id);
                        notification.read = true;
                        item.classList.remove("is-unread");
                        item.removeAttribute("role");
                        item.removeAttribute("tabindex");
                        item.removeAttribute("aria-label");
                        if (notification.target_url) {
                            window.location.assign(notification.target_url);
                        } else {
                            await loadNotifications();
                        }
                    } catch (error) {
                        message.textContent = error.message;
                        message.classList.add("notification-error");
                    }
                };
                if (!notification.read) {
                    item.addEventListener("click", openNotification);
                    item.addEventListener("keydown", (event) => {
                        if (event.key === "Enter" || event.key === " ") {
                            event.preventDefault();
                            openNotification();
                        }
                    });
                } else if (notification.target_url) {
                    item.classList.add("has-link");
                    item.tabIndex = 0;
                    item.setAttribute("role", "link");
                    item.addEventListener("click", () => window.location.assign(notification.target_url));
                    item.addEventListener("keydown", (event) => {
                        if (event.key === "Enter") {
                            window.location.assign(notification.target_url);
                        }
                    });
                }
                list.append(item);
            });
        };

        const loadNotifications = async () => {
            try {
                const url = new URL(endpoint, window.location.href);
                url.searchParams.set("role", role);
                const response = await fetch(url, { credentials: "same-origin", cache: "no-store" });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.error || "Could not load notifications.");
                }
                renderCount(result.unread_count);
                renderNotifications(Array.isArray(result.notifications) ? result.notifications : []);
            } catch (error) {
                list.replaceChildren();
                const failure = document.createElement("p");
                failure.className = "notification-empty notification-error";
                failure.textContent = error.message || "Could not load notifications.";
                list.append(failure);
            }
        };

        trigger.addEventListener("click", async () => {
            const opening = panel.hidden;
            panel.hidden = !opening;
            trigger.setAttribute("aria-expanded", String(opening));
            if (opening) {
                await loadNotifications();
            }
        });

        markAll.addEventListener("click", async () => {
            markAll.disabled = true;
            try {
                const response = await fetch(endpoint, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        "Content-Type": "application/json",
                        "X-CSRF-Token": csrfToken || ""
                    },
                    body: JSON.stringify({ role, action: "mark_all_read" })
                });
                const result = await response.json();
                if (!response.ok || !result.success) {
                    throw new Error(result.error || "Could not update notifications.");
                }
                renderCount(0);
                await loadNotifications();
            } catch (error) {
                list.replaceChildren();
                const failure = document.createElement("p");
                failure.className = "notification-empty notification-error";
                failure.textContent = error.message || "Could not update notifications.";
                list.append(failure);
            } finally {
                markAll.disabled = false;
            }
        });

        document.addEventListener("click", (event) => {
            if (!widget.contains(event.target)) {
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

        loadNotifications();
        window.setInterval(loadNotifications, 60000);
    });
})();
