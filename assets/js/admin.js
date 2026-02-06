(function () {
  "use strict";

  function postAjax(data) {
    var body = new URLSearchParams(data).toString();
    return fetch(atumMailerAdmin.ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: body,
      credentials: "same-origin",
    }).then(function (res) {
      return res.json();
    });
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  function initFromBuilder() {
    var field = document.getElementById("atum-from-email-field");
    if (!field) {
      return;
    }

    var quickButtons = document.querySelectorAll(".atum-quick-fill-email");
    quickButtons.forEach(function (btn) {
      btn.addEventListener("click", function () {
        var value = btn.getAttribute("data-email") || "";
        if (!value) {
          return;
        }
        field.value = value;
        field.dispatchEvent(new Event("change", { bubbles: true }));
      });
    });

    var buildBtn = document.getElementById("atum-from-build");
    var localPart = document.getElementById("atum-from-local-part");
    if (!buildBtn || !localPart) {
      return;
    }

    buildBtn.addEventListener("click", function () {
      var root = buildBtn.closest(".atum-from-builder__compose");
      if (!root) {
        return;
      }
      var domainNode = root.querySelector("span");
      var domain = "";
      if (domainNode) {
        domain = (domainNode.textContent || "").replace(/^@/, "").trim();
      }
      var local = (localPart.value || "").trim();
      if (!local || !domain) {
        return;
      }
      field.value = local + "@" + domain;
      field.dispatchEvent(new Event("change", { bubbles: true }));
    });
  }

  function initEmailAdder() {
    var root = document.getElementById("atum-test-email-adder");
    if (!root) {
      return;
    }

    var chipsNode = document.getElementById("atum-test-email-chips");
    var input = document.getElementById("atum_test_email_input");
    var hidden = document.getElementById("atum_test_email");
    var addBtn = document.getElementById("atum-test-email-add");
    var feedback = document.getElementById("atum-test-email-feedback");
    if (!chipsNode || !input || !hidden || !addBtn) {
      return;
    }

    var emails = [];

    function setFeedback(message, isError) {
      if (!feedback) {
        return;
      }
      feedback.textContent = message || "";
      feedback.classList.toggle("is-success", !isError && !!message);
      feedback.classList.toggle("is-error", !!isError && !!message);
    }

    function syncHidden() {
      hidden.value = emails.join(",");
    }

    function renderChips() {
      chipsNode.innerHTML = "";
      emails.forEach(function (email) {
        var chip = document.createElement("span");
        chip.className = "atum-email-chip";
        chip.setAttribute("data-email", email);
        chip.innerHTML =
          '<span class="atum-email-chip__text"></span><button type="button" class="atum-email-chip__remove"><span aria-hidden="true">&times;</span></button>';
        var text = chip.querySelector(".atum-email-chip__text");
        var removeBtn = chip.querySelector(".atum-email-chip__remove");
        if (text) {
          text.textContent = email;
        }
        if (removeBtn) {
          removeBtn.setAttribute(
            "aria-label",
            (atumMailerAdmin.i18n.removeRecipient || "Remove") + " " + email
          );
          removeBtn.addEventListener("click", function () {
            emails = emails.filter(function (item) {
              return item !== email;
            });
            syncHidden();
            renderChips();
            setFeedback(atumMailerAdmin.i18n.recipientRemoved, false);
          });
        }
        chipsNode.appendChild(chip);
      });
    }

    function addEmail(raw) {
      var email = (raw || "").trim().toLowerCase();
      if (!email) {
        setFeedback("", false);
        return;
      }
      if (!isValidEmail(email)) {
        setFeedback(atumMailerAdmin.i18n.invalidEmail, true);
        return;
      }
      if (emails.indexOf(email) !== -1) {
        setFeedback(atumMailerAdmin.i18n.duplicateEmail, true);
        input.value = "";
        return;
      }
      emails.push(email);
      syncHidden();
      renderChips();
      setFeedback(atumMailerAdmin.i18n.recipientAdded, false);
      input.value = "";
    }

    if (hidden.value) {
      hidden.value
        .split(/[\s,;]+/)
        .map(function (item) {
          return item.trim().toLowerCase();
        })
        .filter(function (item) {
          return item && isValidEmail(item);
        })
        .forEach(function (item) {
          if (emails.indexOf(item) === -1) {
            emails.push(item);
          }
        });
      syncHidden();
      renderChips();
    }

    addBtn.addEventListener("click", function () {
      addEmail(input.value);
    });

    input.addEventListener("keydown", function (event) {
      if ("Enter" === event.key) {
        event.preventDefault();
        addEmail(input.value);
      }
    });
  }

  function initTabsKeyboardNavigation() {
    var tabs = Array.prototype.slice.call(
      document.querySelectorAll(".atum-mailer-tab[role='tab']")
    );
    if (!tabs.length) {
      return;
    }

    function moveFocus(current, step) {
      var index = tabs.indexOf(current);
      if (index < 0) {
        return;
      }
      var next = index + step;
      if (next < 0) {
        next = tabs.length - 1;
      }
      if (next >= tabs.length) {
        next = 0;
      }
      tabs[next].focus();
    }

    tabs.forEach(function (tab) {
      tab.addEventListener("keydown", function (event) {
        if ("ArrowRight" === event.key) {
          event.preventDefault();
          moveFocus(tab, 1);
        } else if ("ArrowLeft" === event.key) {
          event.preventDefault();
          moveFocus(tab, -1);
        } else if ("Home" === event.key) {
          event.preventDefault();
          tabs[0].focus();
        } else if ("End" === event.key) {
          event.preventDefault();
          tabs[tabs.length - 1].focus();
        }
      });
    });
  }

  function initTokenReveal() {
    var btn = document.getElementById("atum-token-reveal");
    var field = document.getElementById("atum-postmark-token-field");
    if (!btn || !field) {
      return;
    }

    btn.addEventListener("click", function () {
      var current = btn.getAttribute("data-state") || "hidden";
      if ("shown" === current) {
        field.type = "password";
        field.value = field.getAttribute("data-masked") || "";
        btn.setAttribute("data-state", "hidden");
        btn.textContent = atumMailerAdmin.i18n.showKey;
        return;
      }

      if (!atumMailerAdmin.tokenRevealAllowed) {
        window.alert(atumMailerAdmin.i18n.tokenRevealDisabled);
        return;
      }

      if ("1" === btn.getAttribute("data-loaded")) {
        field.type = "text";
        btn.setAttribute("data-state", "shown");
        btn.textContent = atumMailerAdmin.i18n.hideKey;
        return;
      }

      btn.disabled = true;
      postAjax({
        action: "atum_mailer_reveal_token",
        nonce: atumMailerAdmin.nonce,
        stage: "request",
      })
        .then(function (json) {
          if (!json || !json.success || !json.data) {
            throw new Error(atumMailerAdmin.i18n.revealError);
          }

          if (false === json.data.allowed) {
            field.value = json.data.masked || field.value;
            throw new Error(json.data.message || atumMailerAdmin.i18n.tokenRevealDisabled);
          }

          if (!json.data.needsConfirm || !json.data.session || !json.data.freshNonce) {
            throw new Error(atumMailerAdmin.i18n.revealError);
          }

          if (!window.confirm(atumMailerAdmin.i18n.confirmReveal)) {
            return null;
          }

          return postAjax({
            action: "atum_mailer_reveal_token",
            nonce: atumMailerAdmin.nonce,
            stage: "confirm",
            session: json.data.session,
            fresh_nonce: json.data.freshNonce,
          });
        })
        .then(function (json) {
          if (!json) {
            return;
          }
          if (!json.success || !json.data || !json.data.token) {
            throw new Error(atumMailerAdmin.i18n.revealError);
          }
          field.type = "text";
          field.value = json.data.token;
          btn.setAttribute("data-loaded", "1");
          btn.setAttribute("data-state", "shown");
          btn.textContent = atumMailerAdmin.i18n.hideKey;
        })
        .catch(function (err) {
          window.alert((err && err.message) || atumMailerAdmin.i18n.revealError);
        })
        .finally(function () {
          btn.disabled = false;
        });
    });
  }

  function initLogDrawer() {
    var drawer = document.getElementById("atum-log-drawer");
    if (!drawer) {
      return;
    }

    var panel = drawer.querySelector(".atum-log-drawer__panel");
    var buttons = document.querySelectorAll(".atum-log-view");
    var closeButtons = drawer.querySelectorAll("[data-atum-close='1']");
    var initialFocusNode = drawer.querySelector("[data-atum-initial-focus='1']");
    var resendLogId = drawer.querySelector("[data-atum-resend-log-id='1']");
    var resendTo = drawer.querySelector("[data-atum-resend-to='1']");
    var resendSubject = drawer.querySelector("[data-atum-resend-subject='1']");
    var resendMode = drawer.querySelector("[data-atum-resend-mode='1']");
    var lastTrigger = null;

    function focusableNodes() {
      if (!panel) {
        return [];
      }
      return Array.prototype.slice.call(
        panel.querySelectorAll(
          "a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex='-1'])"
        )
      );
    }

    function closeDrawer() {
      drawer.classList.remove("is-open");
      drawer.setAttribute("aria-hidden", "true");
      document.body.classList.remove("atum-drawer-open");
      if (lastTrigger && "function" === typeof lastTrigger.focus) {
        lastTrigger.focus();
      }
      lastTrigger = null;
    }

    function openDrawer(trigger) {
      lastTrigger = trigger || document.activeElement;
      drawer.classList.add("is-open");
      drawer.setAttribute("aria-hidden", "false");
      document.body.classList.add("atum-drawer-open");
      if (initialFocusNode && "function" === typeof initialFocusNode.focus) {
        initialFocusNode.focus();
      } else if (panel && "function" === typeof panel.focus) {
        panel.focus();
      }
    }

    function setField(name, value) {
      var node = drawer.querySelector("[data-atum-field='" + name + "']");
      if (!node) {
        return;
      }
      node.textContent = value || "";
    }

    function renderTimeline(items) {
      var node = drawer.querySelector("[data-atum-field='timeline']");
      if (!node) {
        return;
      }
      node.innerHTML = "";

      if (!Array.isArray(items) || !items.length) {
        var empty = document.createElement("li");
        empty.className = "atum-log-timeline__item is-empty";
        empty.textContent = atumMailerAdmin.i18n.unknownLabel;
        node.appendChild(empty);
        return;
      }

      items.forEach(function (item) {
        var li = document.createElement("li");
        var tone = (item && item.tone) || "muted";
        li.className = "atum-log-timeline__item";
        li.innerHTML =
          '<span class="atum-log-timeline__dot"></span><div class="atum-log-timeline__content"><div class="atum-log-timeline__head"><strong></strong><span class="atum-log-timeline__time"></span></div><p class="atum-log-timeline__detail"></p></div>';

        var dot = li.querySelector(".atum-log-timeline__dot");
        var label = li.querySelector("strong");
        var time = li.querySelector(".atum-log-timeline__time");
        var detail = li.querySelector(".atum-log-timeline__detail");

        if (dot) {
          dot.classList.add("is-" + tone);
        }
        if (label) {
          label.textContent = (item && item.label) || atumMailerAdmin.i18n.unknownLabel;
        }
        if (time) {
          time.textContent = (item && item.time) || "";
        }
        if (detail) {
          detail.textContent = (item && item.detail) || "";
          detail.style.display = detail.textContent ? "block" : "none";
        }

        node.appendChild(li);
      });
    }

    function fillDetails(data) {
      if (resendLogId) {
        resendLogId.value = data.id || "";
      }
      if (resendTo) {
        resendTo.value = data.recipient_csv || "";
      }
      if (resendSubject) {
        resendSubject.value = data.subject || "";
      }
      if (resendMode) {
        resendMode.value = data.delivery_mode || "";
      }

      setField("subject", data.subject || "");
      setField("status", data.status || atumMailerAdmin.i18n.unknownLabel);
      setField("created_at", data.created_at || "");
      setField("to", data.to || "");
      setField("http_status", data.http_status || "-");
      setField("provider_message_id", data.provider_message_id || "-");
      setField("error_message", data.error_message || "");
      setField("message", data.message || "");
      setField("headers", data.headers || "");
      setField("attachments", data.attachments || "");
      setField("request_payload", data.request_payload || "");
      setField("response_body", data.response_body || "");
      setField("delivery_mode", data.delivery_mode || "-");
      setField("attempt_count", data.attempt_count || "0");
      setField("next_attempt_at", data.next_attempt_at || "-");
      setField("last_error_code", data.last_error_code || "-");
      setField("webhook_event_type", data.webhook_event_type || "-");
      renderTimeline(data.timeline || []);
    }

    function loadingState() {
      if (resendLogId) {
        resendLogId.value = "";
      }
      if (resendTo) {
        resendTo.value = "";
      }
      if (resendSubject) {
        resendSubject.value = "";
      }
      if (resendMode) {
        resendMode.value = "";
      }

      setField("subject", atumMailerAdmin.i18n.loading);
      setField("status", "");
      setField("created_at", "");
      setField("to", "");
      setField("http_status", "");
      setField("provider_message_id", "");
      setField("error_message", "");
      setField("message", "");
      setField("headers", "");
      setField("attachments", "");
      setField("request_payload", "");
      setField("response_body", "");
      setField("delivery_mode", "");
      setField("attempt_count", "");
      setField("next_attempt_at", "");
      setField("last_error_code", "");
      setField("webhook_event_type", "");
      renderTimeline([]);
    }

    closeButtons.forEach(function (button) {
      button.addEventListener("click", closeDrawer);
    });

    document.addEventListener("keydown", function (event) {
      if (!drawer.classList.contains("is-open")) {
        return;
      }
      if ("Escape" === event.key) {
        closeDrawer();
        return;
      }
      if ("Tab" !== event.key) {
        return;
      }
      var focusables = focusableNodes();
      if (!focusables.length) {
        if (panel && "function" === typeof panel.focus) {
          panel.focus();
          event.preventDefault();
        }
        return;
      }
      var first = focusables[0];
      var last = focusables[focusables.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });

    buttons.forEach(function (button) {
      button.addEventListener("click", function () {
        var logId = button.getAttribute("data-log-id");
        if (!logId) {
          return;
        }

        openDrawer(button);
        loadingState();

        postAjax({
          action: "atum_mailer_get_log_details",
          nonce: atumMailerAdmin.nonce,
          log_id: logId,
        })
          .then(function (json) {
            if (!json || !json.success || !json.data) {
              throw new Error(atumMailerAdmin.i18n.loadError);
            }
            fillDetails(json.data);
          })
          .catch(function () {
            setField("subject", atumMailerAdmin.i18n.loadError);
            setField("status", "");
          });
      });
    });

    if (panel) {
      panel.addEventListener("click", function (event) {
        event.stopPropagation();
      });
    }
  }

  function initLogsBulkActions() {
    var selectAll = document.getElementById("atum-log-select-all");
    var checks = Array.prototype.slice.call(
      document.querySelectorAll(".atum-log-select")
    );
    var forms = Array.prototype.slice.call(
      document.querySelectorAll(".atum-logs-bulk-form")
    );

    if (!checks.length || !forms.length) {
      return;
    }

    function selectedIds() {
      return checks
        .filter(function (box) {
          return !!box.checked;
        })
        .map(function (box) {
          return box.value;
        });
    }

    if (selectAll) {
      selectAll.addEventListener("change", function () {
        checks.forEach(function (box) {
          box.checked = !!selectAll.checked;
        });
      });
    }

    checks.forEach(function (box) {
      box.addEventListener("change", function () {
        if (!selectAll) {
          return;
        }
        selectAll.checked = checks.length === selectedIds().length;
      });
    });

    forms.forEach(function (form) {
      form.addEventListener("submit", function (event) {
        var actionSelect = form.querySelector(".atum-logs-bulk-action");
        var action = actionSelect ? actionSelect.value : "";
        var ids = selectedIds();
        var csvInput = form.querySelector(".atum-log-ids-csv");
        if (csvInput) {
          csvInput.value = ids.join(",");
        }

        if (!action) {
          event.preventDefault();
          return;
        }

        if ("retry_selected" === action || "export_selected" === action) {
          if (!ids.length) {
            event.preventDefault();
            window.alert(atumMailerAdmin.i18n.selectLogsRequired);
            return;
          }
        }

        if ("purge_filtered" === action) {
          if (!window.confirm(atumMailerAdmin.i18n.confirmPurgeFiltered)) {
            event.preventDefault();
          }
        }
      });
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    if ("undefined" === typeof atumMailerAdmin) {
      return;
    }
    initFromBuilder();
    initEmailAdder();
    initTabsKeyboardNavigation();
    initTokenReveal();
    initLogDrawer();
    initLogsBulkActions();
  });
})();
