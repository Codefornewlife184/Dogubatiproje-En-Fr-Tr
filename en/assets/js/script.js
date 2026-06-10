$(document).ready(function () {
  var $form = $("#contact-form");
  if (!$form.length) return;

  var $status = $("#form-status");
  var $submitButton = $form.find('button[type="submit"]');
  var defaultButtonText = $submitButton.text();

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function renderStatus(type, title, message) {
    if (!$status.length) return;
    var safeTitle = escapeHtml(title || "");
    var safeMessage = escapeHtml(message || "").replace(/\n/g, "<br>");
    $status
      .removeClass("is-success is-error is-loading")
      .addClass("is-" + type)
      .html(
        '<div class="form-status-card">' +
          '<strong class="form-status-title">' + safeTitle + "</strong>" +
          '<div class="form-status-text">' + safeMessage + "</div>" +
        "</div>"
      )
      .stop(true, true)
      .hide()
      .fadeIn(200);
  }

  $form.on("submit", function (event) {
    event.preventDefault();

    if ($submitButton.length) {
      $submitButton.prop("disabled", true).text("SENDING...");
    }

    renderStatus("loading", "Sending", "Your message is being processed, please wait.");

    $.ajax({
      type: "POST",
      url: $form.attr("action"),
      data: $form.serialize(),
      dataType: "json",
      headers: {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
      },
    })
      .done(function (response) {
        if (response && response.success) {
          renderStatus(
            "success",
            response.title || "Message sent",
            response.message || "Your message has been delivered successfully."
          );
          $form.trigger("reset");
          return;
        }

        renderStatus(
          "error",
          (response && response.title) || "Sending failed",
          (response && response.message) || "There was a problem while sending your message."
        );
      })
      .fail(function (xhr) {
        var response = xhr.responseJSON || {};
        renderStatus(
          "error",
          response.title || "Sending failed",
          response.message || "There was a problem reaching the server."
        );
      })
      .always(function () {
        if ($submitButton.length) {
          $submitButton.prop("disabled", false).text(defaultButtonText);
        }
      });
  });
});
