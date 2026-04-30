<?php if ( ! defined( 'WPINC' ) ) die; ?>
<div id="ai-sales-agent-widget">
    <!-- Botón flotante -->
    <button id="ai-sales-agent-toggle" class="ai-sales-agent-toggle-btn" aria-label="Abrir chat">
        <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
        </svg>
        <span class="elizabeth-notification-badge" aria-hidden="true">1</span>
    </button>

    <!-- Contenedor del chat -->
    <div id="ai-sales-agent-container" class="ai-sales-agent-container" style="display:none;" role="dialog" aria-label="Chat con Elizabeth">
        <!-- Header -->
        <div class="ai-sales-agent-header">
            <div class="ai-header-profile">
                <div class="ai-header-avatar" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2a10 10 0 1 0 0 20 10 10 0 1 0 0-20z"></path>
                        <path d="M12 16v-4"></path>
                        <path d="M12 8h.01"></path>
                    </svg>
                </div>
                <div class="ai-header-info">
                    <h3>Elizabeth</h3>
                    <p><span class="ai-status-dot" aria-hidden="true"></span> En línea</p>
                </div>
            </div>
            <button id="ai-sales-agent-close" class="ai-sales-agent-close-btn" aria-label="Cerrar chat">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>

        <!-- Onboarding -->
        <div id="ai-sales-agent-onboarding" class="ai-sales-agent-onboarding">
            <div class="ai-onboarding-content">
                <h4>¡Hola! 👋</h4>
                <p>Por favor, dinos tu nombre para comenzar a chatear.</p>

                <div class="ai-form-group">
                    <input
                        type="text"
                        id="elizabeth-user-name"
                        placeholder="Tu nombre"
                        autocomplete="given-name"
                        required
                    >
                </div>

                <!-- Honeypot anti-bot -->
                <input type="text" id="elizabeth-hp" name="elizabeth_email_confirm" style="position:absolute;left:-5000px;" tabindex="-1" autocomplete="off">

                <p id="elizabeth-name-error" class="ai-form-error" style="display:none;">Por favor, escribe tu nombre para continuar.</p>

                <button id="ai-sales-agent-start" class="ai-sales-agent-start-btn">Comenzar</button>
            </div>
        </div>

        <!-- Chat -->
        <div id="ai-sales-agent-chat-flow" style="display:none;flex-direction:column;flex:1;overflow:hidden;">
            <div id="ai-sales-agent-messages" class="ai-sales-agent-messages" role="log" aria-live="polite">
                <div class="ai-message ai-message-system" id="elizabeth-greeting">
                    ¡Hola! 👋 Estoy aquí para ayudarte a encontrar el producto perfecto. ¿En qué te puedo ayudar hoy?
                </div>
            </div>

            <div class="ai-sales-agent-input-area">
                <textarea
                    id="ai-sales-agent-input"
                    placeholder="Escribe tu mensaje..."
                    rows="1"
                    aria-label="Mensaje"
                ></textarea>
                <button id="ai-sales-agent-send" class="ai-sales-agent-send-btn" disabled aria-label="Enviar">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>
