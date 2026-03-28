document.addEventListener('DOMContentLoaded', function() {
    var button = document.getElementById('local-skillradar-random-by-skill-link');
    if (!button) {
        return;
    }

    var target = document.querySelector('.page-add-actions.commands');
    if (target) {
        button.classList.remove('d-none');
        button.classList.add('ms-2');
        target.insertAdjacentElement('afterend', button);
        return;
    }

    button.classList.remove('d-none');
});
