/**
 * AI Sales Agent Frontend Script
 */

document.addEventListener('DOMContentLoaded', function () {

    let isConfigured = true;
    if (typeof aiSalesAgentData === 'undefined' || !aiSalesAgentData.nonce) {
        isConfigured = false;
    }

    // Precalculado una vez: PHP ya garantiza que responseDelay está entre 5 y 60.
    const BASE_DELAY = isConfigured ? (aiSalesAgentData.responseDelay || 18) * 1000 : 18000;

    // Helper de traducción: obtiene un string del objeto i18n pasado por PHP.
    // ti() acepta un objeto de reemplazos para interpolación (ej. {name}).
    const _i18n = (isConfigured && aiSalesAgentData.i18n) ? aiSalesAgentData.i18n : {};
    function t(key) { return _i18n[key] || key; }
    function ti(key, vars) {
        return Object.entries(vars).reduce(
            function(str, pair) { return str.replace('{' + pair[0] + '}', pair[1]); },
            t(key)
        );
    }

    const toggleBtn    = document.getElementById('ai-sales-agent-toggle');
    const container    = document.getElementById('ai-sales-agent-container');
    const closeBtn     = document.getElementById('ai-sales-agent-close');
    const messagesArea = document.getElementById('ai-sales-agent-messages');
    const inputField   = document.getElementById('ai-sales-agent-input');
    const sendBtn      = document.getElementById('ai-sales-agent-send');

    if (!toggleBtn || !container) return;

    // Session ID para rastreo de conversación — localStorage para persistir entre pestañas
    let sessionId = localStorage.getItem('elizabeth_session_id');
    if (!sessionId) {
        sessionId = 'sess_' + Array.from(
            crypto.getRandomValues(new Uint8Array(16)),
            function(b) { return b.toString(16).padStart(2, '0'); }
        ).join('');
        localStorage.setItem('elizabeth_session_id', sessionId);
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function linkify(text) {
        return text.replace(/(https?:\/\/[^\s<>"]+)/g, function (url) {
            return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a>';
        });
    }

    function sanitizeSystemHtml(html) {
        return String(html)
            .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
            .replace(/\son\w+\s*=\s*(?:"[^"]*"|'[^']*'|[^\s>]*)/gi, '')
            .replace(/javascript\s*:/gi, '');
    }

    // ── Catálogo ──────────────────────────────────────────────────────────────
    let inventoryCache   = null;
    let inventoryPromise = null;

    async function loadInventory() {
        try {
            const inventoryUrl = aiSalesAgentData.inventoryUrl
                || (aiSalesAgentData.siteUrl || window.location.origin).replace(/\/$/, '') + '/wp-json/elizabeth/v1/inventory';

            const res = await fetch(inventoryUrl);
            if (res.ok) {
                const data = await res.json();
                inventoryCache = Array.isArray(data) ? data : [];
            } else {
                inventoryCache = [];
            }
        } catch (e) {
            inventoryCache = [];
        }
    }

    // Cargar catálogo de inmediato; mantener el botón deshabilitado hasta que termine
    if (isConfigured && sendBtn) {
        sendBtn.disabled = true;
        inventoryPromise = loadInventory().finally(() => {
            inventoryPromise = null;
            if (inputField.value.trim() === '') sendBtn.disabled = true;
        });
    }

    // ── Producto actual ───────────────────────────────────────────────────────
    function detectCurrentProduct() {
        if (aiSalesAgentData.currentProduct) return aiSalesAgentData.currentProduct;
        try {
            const scripts = document.querySelectorAll('script[type="application/ld+json"]');
            for (const s of scripts) {
                const data = JSON.parse(s.textContent || '{}');
                if (data['@type'] === 'Product') {
                    const offer = Array.isArray(data.offers) ? data.offers[0] : data.offers;
                    return {
                        name:              data.name || null,
                        short_description: data.description
                            ? String(data.description).replace(/<[^>]+>/g, '').slice(0, 300)
                            : null,
                        price:     offer?.price ?? null,
                        sku:       data.sku || null,
                        stock:     offer?.availability?.includes('InStock') ? 'Disponible' : 'Consultar',
                        permalink: window.location.href,
                    };
                }
            }
        } catch (_) {}
        return null;
    }

    // ── Persistencia de mensajes ──────────────────────────────────────────────
    let messageHistory = JSON.parse(localStorage.getItem('elizabeth_messages') || '[]');
    const greetingDiv  = document.getElementById('elizabeth-greeting');

    if (messageHistory.length > 0) {
        messagesArea.innerHTML = '';
        messageHistory.forEach(function (msg) {
            const msgDiv = document.createElement('div');
            msgDiv.className = 'ai-message ai-message-' + msg.sender;
            if (msg.sender === 'ai') {
                msgDiv.innerHTML = linkify(escapeHtml(msg.text)).replace(/\n/g, '<br>');
            } else if (msg.sender === 'system') {
                msgDiv.innerHTML = sanitizeSystemHtml(msg.text);
            } else {
                msgDiv.innerHTML = escapeHtml(msg.text).replace(/\n/g, '<br>');
            }
            messagesArea.appendChild(msgDiv);
        });
    }

    const isWidgetOpen = localStorage.getItem('elizabeth_widget_open') === 'true';
    if (isWidgetOpen) {
        container.style.display = 'flex';
        const badge = toggleBtn.querySelector('.elizabeth-notification-badge');
        if (badge) badge.style.display = 'none';
    }

    const storedNameInit = localStorage.getItem('elizabeth_user_name');
    if (storedNameInit) {
        document.getElementById('ai-sales-agent-onboarding').style.display = 'none';
        document.getElementById('ai-sales-agent-chat-flow').style.display  = 'flex';
        if (messageHistory.length === 0 && greetingDiv) {
            greetingDiv.innerHTML = ti('personalGreeting', { name: escapeHtml(storedNameInit) });
        }
        if (isWidgetOpen) {
            setTimeout(function () { inputField.focus(); scrollToBottom(); }, 100);
        }
    }

    // ── Toggle ────────────────────────────────────────────────────────────────
    toggleBtn.addEventListener('click', function () {
        const opening = container.style.display === 'none';
        container.style.display = opening ? 'flex' : 'none';
        localStorage.setItem('elizabeth_widget_open', opening ? 'true' : 'false');

        if (opening) {
            const badge = toggleBtn.querySelector('.elizabeth-notification-badge');
            if (badge) badge.style.display = 'none';

            if (localStorage.getItem('elizabeth_user_name')) {
                document.getElementById('ai-sales-agent-onboarding').style.display = 'none';
                document.getElementById('ai-sales-agent-chat-flow').style.display  = 'flex';
                inputField.focus();
            } else {
                document.getElementById('elizabeth-user-name').focus();
            }
            scrollToBottom();
        }
    });

    // ── Onboarding ────────────────────────────────────────────────────────────
    const startBtn = document.getElementById('ai-sales-agent-start');
    if (startBtn) {
        startBtn.addEventListener('click', function () {
            const nameInput    = document.getElementById('elizabeth-user-name');
            const hpInput      = document.getElementById('elizabeth-hp');
            const errorEl      = document.getElementById('elizabeth-name-error');
            const onboardingDiv = document.getElementById('ai-sales-agent-onboarding');
            const chatFlowDiv  = document.getElementById('ai-sales-agent-chat-flow');
            const greetingEl   = document.getElementById('elizabeth-greeting');

            // Honeypot anti-bot
            if (hpInput && hpInput.value.trim() !== '') return;

            const userName = nameInput ? nameInput.value.trim().slice(0, 50) : '';
            if (userName === '') {
                if (errorEl) errorEl.style.display = 'block';
                if (nameInput) nameInput.focus();
                return;
            }

            if (errorEl) errorEl.style.display = 'none';
            localStorage.setItem('elizabeth_user_name', userName);

            if (greetingEl) {
                const greetingText = ti('personalGreeting', { name: escapeHtml(userName) });
                greetingEl.innerHTML = greetingText;
                if (messageHistory.length === 0) {
                    messageHistory.push({ text: greetingText, sender: 'system' });
                    if (messageHistory.length > 30) messageHistory.splice(0, messageHistory.length - 30);
                    localStorage.setItem('elizabeth_messages', JSON.stringify(messageHistory));
                }
            }

            onboardingDiv.style.display = 'none';
            chatFlowDiv.style.display   = 'flex';
            inputField.focus();
            scrollToBottom();
        });

        document.getElementById('elizabeth-user-name').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') startBtn.click();
        });
    }

    closeBtn.addEventListener('click', function () {
        container.style.display = 'none';
        localStorage.setItem('elizabeth_widget_open', 'false');
    });

    // ── Input ─────────────────────────────────────────────────────────────────
    inputField.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
        sendBtn.disabled = this.value.trim() === '';
    });

    inputField.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });

    sendBtn.addEventListener('click', sendMessage);

    // ── Helpers ───────────────────────────────────────────────────────────────
    function scrollToBottom() {
        messagesArea.scrollTop = messagesArea.scrollHeight;
    }

    function appendMessage(text, sender, save) {
        if (save === undefined) save = true;
        const msgDiv = document.createElement('div');
        msgDiv.className = 'ai-message ai-message-' + sender;
        if (sender === 'ai') {
            msgDiv.innerHTML = linkify(escapeHtml(text)).replace(/\n/g, '<br>');
        } else if (sender === 'system') {
            msgDiv.innerHTML = sanitizeSystemHtml(text);
        } else {
            msgDiv.innerHTML = escapeHtml(text).replace(/\n/g, '<br>');
        }
        messagesArea.appendChild(msgDiv);
        if (save) {
            messageHistory.push({ text: text, sender: sender });
            if (messageHistory.length > 30) messageHistory.splice(0, messageHistory.length - 30);
            localStorage.setItem('elizabeth_messages', JSON.stringify(messageHistory));
        }
        scrollToBottom();
    }

    function showTypingIndicator() {
        const indicator = document.createElement('div');
        indicator.className = 'ai-typing-indicator';
        indicator.id = 'ai-typing-indicator';
        for (var i = 0; i < 3; i++) {
            const dot = document.createElement('div');
            dot.className = 'ai-typing-dot';
            indicator.appendChild(dot);
        }
        messagesArea.appendChild(indicator);
        scrollToBottom();
    }

    function removeTypingIndicator() {
        const el = document.getElementById('ai-typing-indicator');
        if (el) el.remove();
    }

    function lockWidget(message) {
        const inputArea = document.querySelector('.ai-sales-agent-input-area');
        if (inputArea) {
            const p = document.createElement('p');
            p.style.cssText = 'margin:0;padding:12px 16px;font-size:13px;color:#888;text-align:center;line-height:1.5;';
            p.textContent = '🔒 ' + message;
            inputArea.innerHTML = '';
            inputArea.appendChild(p);
        }
        sendBtn.disabled = true;
    }

    // ── Enviar mensaje ────────────────────────────────────────────────────────
    async function sendMessage() {
        const text = inputField.value.trim();
        if (!text) return;

        inputField.value = '';
        inputField.style.height = 'auto';
        sendBtn.disabled = true;

        appendMessage(text, 'user');

        if (!isConfigured) {
            appendMessage(t('notConfigured'), 'ai');
            return;
        }

        const sendTimestamp = Date.now();
        const targetDelay   = BASE_DELAY + Math.floor(Math.random() * 5000);
        // El indicador de typing aparece solo después de 8 s de silencio
        const typingTimeout = setTimeout(showTypingIndicator, 8000);

        const storedName = localStorage.getItem('elizabeth_user_name') || 'Invitado';

        const conversationHistory = messageHistory
            .filter(function (msg) { return msg.sender === 'user' || msg.sender === 'ai'; })
            .slice(-10)
            .map(function (msg) { return { role: msg.sender === 'ai' ? 'assistant' : 'user', content: msg.text }; });

        // Esperar catálogo si aún está cargando (máx. 8 s)
        if (inventoryPromise) {
            await Promise.race([inventoryPromise, new Promise(function (r) { setTimeout(r, 8000); })]);
        }

        const currentProduct = detectCurrentProduct();

        try {
            const response = await fetch(aiSalesAgentData.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action:          'elizabeth_chat',
                    nonce:           aiSalesAgentData.nonce,
                    message:         text,
                    session_id:      sessionId,
                    customer_name:   storedName,
                    page_url:        window.location.href,
                    current_product: JSON.stringify(currentProduct ?? null),
                    inventory:       JSON.stringify(inventoryCache ?? []),
                    history:         JSON.stringify(conversationHistory),
                })
            });

            const envelope = await response.json();
            if (!response.ok || !envelope.success) {
                if (envelope.data?.status === 429) {
                    clearTimeout(typingTimeout);
                    removeTypingIndicator();
                    lockWidget(envelope.data.message || t('rateLimitReached'));
                    return;
                }
                clearTimeout(typingTimeout);
                removeTypingIndicator();
                var serverMsg = (envelope.data && envelope.data.message)
                    ? envelope.data.message
                    : t('processingError');
                appendMessage(serverMsg, 'ai');
                return;
            }

            const data = envelope.data;
            const replyText = (data && (data.reply || data.response))
                ? (data.reply || data.response)
                : t('fallbackReply');

            const elapsed   = Date.now() - sendTimestamp;
            const remaining = Math.max(0, targetDelay - elapsed);
            if (remaining > 0) {
                await new Promise(function (r) { setTimeout(r, remaining); });
            }

            clearTimeout(typingTimeout);
            removeTypingIndicator();
            appendMessage(replyText, 'ai');

        } catch (error) {
            const elapsed   = Date.now() - sendTimestamp;
            const remaining = Math.max(0, targetDelay - elapsed);
            if (remaining > 0) {
                await new Promise(function (r) { setTimeout(r, remaining); });
            }
            clearTimeout(typingTimeout);
            removeTypingIndicator();
            appendMessage(t('networkError'), 'ai');
        }
    }
});
