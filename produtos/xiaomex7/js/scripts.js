document.querySelectorAll('.size-btn').forEach(button => {
    button.addEventListener('click', function() {
        document.querySelectorAll('.size-btn').forEach(btn => btn.classList.remove('active'));
        this.classList.add('active');
    });
});


document.getElementById('favoriteButton').addEventListener('click', function() {
    this.classList.toggle('active');
    var icon = document.getElementById('favoriteIcon');
    var text = document.getElementById('favoriteText');
    if (icon.textContent === 'favorite_border') {
        icon.textContent = 'favorite';
        icon.style.color = 'red';
        text.textContent = 'Item favoritado';
    } else {
        icon.textContent = 'favorite_border';
        icon.style.color = '#007bff';
        text.textContent = 'Adicionar aos favoritos';
    }
});

document.getElementById('shareButton').addEventListener('click', function() {
    var currentUrl = window.location.href;
    var shareUrl = currentUrl.includes('?') ? currentUrl + '&utm_source=compartilhar' : currentUrl + '?utm_source=compartilhar';
    navigator.clipboard.writeText(shareUrl).then(function() {
        var notification = document.getElementById('notification');
        notification.classList.remove('hidden');
        notification.classList.add('show');
        setTimeout(function() {
            notification.classList.remove('show');
            notification.classList.add('hidden');
        }, 2000);
    }, function(err) {
        console.error('Erro ao copiar o link: ', err);
    });
});





function toggleLike(button) {
    const dislikeButton = button.nextElementSibling;
    const countSpan = button.querySelector('.count');
    let count = parseInt(countSpan.textContent);

    if (button.classList.contains('active')) {
        button.classList.remove('active');
        count -= 1;
    } else {
        button.classList.add('active');
        count += 1;
        dislikeButton.classList.remove('active');
    }

    countSpan.textContent = count;
}

function toggleDislike(button) {
    const likeButton = button.previousElementSibling;
    const countSpan = likeButton.querySelector('.count');
    let count = parseInt(countSpan.textContent);

    if (button.classList.contains('active')) {
        button.classList.remove('active');
    } else {
        button.classList.add('active');
        if (likeButton.classList.contains('active')) {
            likeButton.classList.remove('active');
            count -= 1;
        }
    }

    countSpan.textContent = count;
}

