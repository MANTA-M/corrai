document.getElementById('pilot-form').addEventListener('submit', function (event) {
  event.preventDefault();

  var email = document.getElementById('email').value;

  fetch('/corrai_test/api/volunteer', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email: email })
  }).then(function (response) {
    if (!response.ok) {
      throw new Error('request failed');
    }
    alert('Merci pour votre candidature, nous allons vous contacter dès que possible.');
  }).catch(function () {
    alert('Une erreur est survenue');
  });
});
