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
      $submitButton.prop("disabled", true).text("ENVOI...");
    }

    renderStatus("loading", "Envoi", "Votre message est en cours de traitement, veuillez patienter.");

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
            response.title || "Message envoye",
            response.message || "Votre message a ete envoye avec succes."
          );
          $form.trigger("reset");
          return;
        }

        renderStatus(
          "error",
          (response && response.title) || "Echec de l'envoi",
          (response && response.message) || "Un probleme est survenu lors de l'envoi de votre message."
        );
      })
      .fail(function (xhr) {
        var response = xhr.responseJSON || {};
        renderStatus(
          "error",
          response.title || "Echec de l'envoi",
          response.message || "Un probleme est survenu lors de la connexion au serveur."
        );
      })
      .always(function () {
        if ($submitButton.length) {
          $submitButton.prop("disabled", false).text(defaultButtonText);
        }
      });
  });
});
