/**
 * Floating BarangayConnect chatbot widget.
 *
 * BarangayConnectChatbotWidget.init({
 *   userRole: 'resident' | 'admin' | 'guest',
 *   residentUserId: 0,
 *   submitterName: '',
 *   title: 'Barangay Assistant',
 * });
 */
(function (global) {
  const END_CHAT_LABEL = 'End this chat';

  function escapeHtml(text) {
    return String(text || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function formatBotText(text) {
    return escapeHtml(text).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  }

  function getIdentity(options) {
    const role = options.userRole || 'guest';
    let residentUserId = options.residentUserId || 0;
    let submitterName = options.submitterName || '';

    if (role === 'resident') {
      residentUserId =
        residentUserId ||
        parseInt(global.localStorage.getItem('residentId') || '0', 10) ||
        0;
      const first = (global.localStorage.getItem('residentFirstname') || '').trim();
      const last = (global.localStorage.getItem('residentLastname') || '').trim();
      submitterName = submitterName || [first, last].filter(Boolean).join(' ').trim();
    }

    return {
      userRole: role,
      residentUserId: residentUserId > 0 ? residentUserId : null,
      submitterName: submitterName,
    };
  }

  function createWidgetDom(options) {
    const root = document.createElement('div');
    root.className = 'bc-chatbot-root';
    root.innerHTML =
      '<button type="button" class="bc-chatbot-toggle" aria-label="Open chat assistant">' +
      '<i class="fas fa-comments"></i></button>' +
      '<div class="bc-chatbot-panel" role="dialog" aria-label="Chat assistant">' +
      '<div class="bc-chatbot-header">' +
      '<div class="bc-chatbot-header-info">' +
      '<div class="bc-chatbot-avatar"><i class="fas fa-robot"></i></div>' +
      '<div>' +
      '<div class="bc-chatbot-title">' +
      escapeHtml(options.title || 'Barangay Assistant') +
      '</div>' +
      '<div class="bc-chatbot-subtitle">' +
      escapeHtml(options.subtitle || 'Online · BarangayConnect') +
      '</div>' +
      '</div></div>' +
      '<div class="bc-chatbot-header-actions">' +
      '<button type="button" class="bc-chatbot-new" aria-label="Start new chat" title="New chat"><i class="fas fa-plus"></i></button>' +
      '<button type="button" class="bc-chatbot-close" aria-label="Close chat"><i class="fas fa-times"></i></button>' +
      '</div></div>' +
      '<div class="bc-chatbot-messages"></div>' +
      '<div class="bc-chatbot-suggestions"></div>' +
      '<div class="bc-chatbot-input-row">' +
      '<input type="text" class="bc-chatbot-input" placeholder="Type your question..." maxlength="500" />' +
      '<button type="button" class="bc-chatbot-send" aria-label="Send message"><i class="fas fa-paper-plane"></i></button>' +
      '</div></div>';

    document.body.appendChild(root);
    return {
      root: root,
      toggle: root.querySelector('.bc-chatbot-toggle'),
      panel: root.querySelector('.bc-chatbot-panel'),
      closeBtn: root.querySelector('.bc-chatbot-close'),
      newBtn: root.querySelector('.bc-chatbot-new'),
      messages: root.querySelector('.bc-chatbot-messages'),
      suggestions: root.querySelector('.bc-chatbot-suggestions'),
      input: root.querySelector('.bc-chatbot-input'),
      sendBtn: root.querySelector('.bc-chatbot-send'),
    };
  }

  function init(options) {
    options = options || {};
    const Api = global.BarangayConnectChatbotApi;
    if (!Api) {
      console.warn('BarangayConnectChatbotApi is not loaded.');
      return null;
    }

    const identity = getIdentity(options);
    const ui = createWidgetDom(options);
    let sessionId = '';
    let busy = false;
    let panelReady = false;
    let endChatTimer = null;

    function scrollToBottom() {
      ui.messages.scrollTop = ui.messages.scrollHeight;
    }

    function appendMessage(role, content) {
      const el = document.createElement('div');
      el.className = 'bc-chatbot-msg ' + role;
      if (role === 'bot') {
        el.innerHTML = formatBotText(content);
      } else {
        el.textContent = content;
      }
      ui.messages.appendChild(el);
      scrollToBottom();
      return el;
    }

    function isEndChatCommand(text) {
      const value = String(text || '').trim().toLowerCase();
      return (
        value === 'end chat' ||
        value === 'end this chat' ||
        value === 'close chat' ||
        value === 'stop chat'
      );
    }

    function setSuggestions(list) {
      ui.suggestions.innerHTML = '';
      if (!list || !list.length) {
        ui.suggestions.style.display = 'none';
        return;
      }
      ui.suggestions.style.display = '';
      list.forEach(function (text) {
        const chip = document.createElement('button');
        chip.type = 'button';
        const isEnd = String(text) === END_CHAT_LABEL;
        chip.className = 'bc-chatbot-chip' + (isEnd ? ' bc-chatbot-chip-end' : '');
        chip.textContent = text;
        chip.addEventListener('click', function () {
          if (isEnd) {
            finishAndCloseChat();
            return;
          }
          const numMatch = String(text).match(/^(\d+)\./);
          ui.input.value = numMatch ? numMatch[1] : text;
          setSuggestions([]);
          sendCurrentMessage();
        });
        ui.suggestions.appendChild(chip);
      });
    }

    function setBusy(state) {
      busy = state;
      ui.sendBtn.disabled = state;
      ui.input.disabled = state;
    }

    function applyGeminiSubtitle(started) {
      if (started && started.geminiEnabled && ui.root.querySelector('.bc-chatbot-subtitle')) {
        ui.root.querySelector('.bc-chatbot-subtitle').textContent = 'Powered by Gemini AI';
      }
    }

    function resetSessionState() {
      sessionId = '';
      panelReady = false;
      if (endChatTimer) {
        clearTimeout(endChatTimer);
        endChatTimer = null;
      }
    }

    function closePanel() {
      ui.panel.classList.remove('open');
      ui.toggle.style.display = '';
    }

    function finishAndCloseChat() {
      setSuggestions([]);
      setBusy(true);
      appendMessage('user', END_CHAT_LABEL);
      appendMessage(
        'bot',
        'Thank you for chatting with BarangayConnect today. Take care, and feel free to come back anytime you need help!'
      );
      endChatTimer = setTimeout(function () {
        closePanel();
        ui.messages.innerHTML = '';
        resetSessionState();
        setBusy(false);
      }, 1800);
    }

    async function createSession(showWelcome) {
      const started = await Api.startSession(identity);
      sessionId = started.sessionId;
      panelReady = true;
      applyGeminiSubtitle(started);
      if (showWelcome && started.welcome) {
        appendMessage('bot', started.welcome);
      }
      setSuggestions([]);
      return started;
    }

    async function startNewChat() {
      resetSessionState();
      ui.messages.innerHTML = '';
      setSuggestions([]);
      setBusy(true);
      try {
        await createSession(true);
      } catch (err) {
        console.error(err);
        appendMessage(
          'bot',
          'Sorry, the chat service is unavailable right now. Please make sure Apache and MySQL are running in XAMPP.'
        );
      } finally {
        setBusy(false);
        ui.input.focus();
      }
    }

    async function ensureSession() {
      if (sessionId) {
        return sessionId;
      }
      await createSession(false);
      return sessionId;
    }

    async function sendCurrentMessage() {
      const text = ui.input.value.trim();
      if (!text || busy) return;

      if (isEndChatCommand(text)) {
        ui.input.value = '';
        finishAndCloseChat();
        return;
      }

      appendMessage('user', text);
      ui.input.value = '';
      setSuggestions([]);
      setBusy(true);

      const typing = appendMessage('bot', 'Typing...');
      typing.classList.add('typing');

      try {
        await ensureSession();
        const result = await Api.sendMessage(sessionId, text);
        typing.remove();
        appendMessage('bot', result.reply || 'Sorry, I could not answer that.');

        if (result.endChat) {
          endChatTimer = setTimeout(function () {
            closePanel();
            ui.messages.innerHTML = '';
            resetSessionState();
          }, 1800);
          setSuggestions([]);
        } else if (result.showClosingChips) {
          setSuggestions(result.suggestions || []);
        } else {
          setSuggestions([]);
        }
      } catch (err) {
        console.error(err);
        typing.remove();
        appendMessage(
          'bot',
          'Sorry, the chat service is unavailable right now. Please make sure Apache and MySQL are running in XAMPP.'
        );
      } finally {
        setBusy(false);
        ui.input.focus();
      }
    }

    async function openPanel() {
      ui.panel.classList.add('open');
      ui.toggle.style.display = 'none';
      if (!panelReady) {
        await startNewChat();
      } else {
        ui.input.focus();
      }
    }

    ui.toggle.addEventListener('click', openPanel);
    ui.closeBtn.addEventListener('click', closePanel);
    ui.newBtn.addEventListener('click', startNewChat);
    ui.sendBtn.addEventListener('click', sendCurrentMessage);
    ui.input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        sendCurrentMessage();
      }
    });

    return {
      open: openPanel,
      close: closePanel,
      send: sendCurrentMessage,
      newChat: startNewChat,
    };
  }

  global.BarangayConnectChatbotWidget = { init: init };
})(window);
