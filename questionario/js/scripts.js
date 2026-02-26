function showAd2() {
    document.getElementById('p1').style.display = 'none';
    document.getElementById('p2').style.display = 'block';

    document.getElementById('progress1').style.display = 'none';
    document.getElementById('progress2').style.display = 'block';
}

function showAd3() {
    document.getElementById('p2').style.display = 'none';
    document.getElementById('p3').style.display = 'block';

    document.getElementById('progress2').style.display = 'none';
    document.getElementById('progress3').style.display = 'block';
}


function showAd4() {
    document.getElementById('p3').style.display = 'none';
    document.getElementById('p4').style.display = 'block';

    document.getElementById('progress3').style.display = 'none';
    document.getElementById('progress4').style.display = 'block';
}


function showAd5() {
    document.getElementById('p4').style.display = 'none';
    document.getElementById('p5').style.display = 'block';

    document.getElementById('progress4').style.display = 'none';
    document.getElementById('progress5').style.display = 'block';
}

function showAd6() {
    document.getElementById('p5').style.display = 'none';
    document.getElementById('p6').style.display = 'block';
    document.getElementById('respondaeganhe').style.display = 'none';
    document.getElementById('barradeprogresso').style.display = 'none';

    document.getElementById('progress4').style.display = 'none';
    document.getElementById('progress5').style.display = 'block';
}


let currentQuestion = 1;
const totalQuestions = 6;

document.addEventListener("DOMContentLoaded", function() {
    updateProgress();
});

function nextQuestion() {
    if (currentQuestion < totalQuestions) {
        document.getElementById(`question${currentQuestion}`).style.display = 'none';
        currentQuestion++;
        document.getElementById(`question${currentQuestion}`).style.display = 'block';
        updateProgress();

        // Verificar e atualizar o texto do botão para "Enviar" na última pergunta
        if (currentQuestion === totalQuestions) {
            document.getElementById('next-button').textContent = 'Resgatar ';
        }

        // Executar função específica para cada botão
        if (currentQuestion === 2) {
            showAd2();
        } else if (currentQuestion === 3) {
            showAd3();
        } else if (currentQuestion === 4) {
            showAd4();
        } else if (currentQuestion === 5) {
            showAd5();
        } else if (currentQuestion === 6) {
            showAd6();
        }
    } else {
        // Redirecionar para outra página HTML ao final do quiz
        window.location.href = '../roleta/index.html' + window.location.search;
    }
}

function updateProgress() {
    const progressPercentage = (currentQuestion / totalQuestions) * 100;
    document.getElementById('progress').style.width = `${progressPercentage}%`;
    document.getElementById('progress-text').textContent = `${Math.round(progressPercentage)} %`;
}
