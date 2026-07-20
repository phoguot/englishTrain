/**
 * Dựng form câu hỏi trắc nghiệm động cho trang tạo/sửa bài tập.
 * Server luôn kiểm tra lại dữ liệu bằng QuizJsonBuilder.
 */
(function () {
    'use strict';

    var typeSelect = document.getElementById('type');
    var quizSection = document.getElementById('quiz-section');
    var container = document.getElementById('questions');
    var addButton = document.getElementById('add-question');

    if (!typeSelect || !quizSection || !container || !addButton) {
        return;
    }

    function toggleQuizSection() {
        var isQuiz = typeSelect.value === 'quiz';
        quizSection.classList.toggle('d-none', !isQuiz);
        if (isQuiz && container.children.length === 0) {
            addQuestion();
        }
    }

    function reindex() {
        Array.prototype.forEach.call(container.children, function (card, questionIndex) {
            card.querySelector('.question-number').textContent = 'Câu ' + (questionIndex + 1);
            card.querySelector('.question-text').name = 'questions[' + questionIndex + '][text]';

            Array.prototype.forEach.call(card.querySelectorAll('.option-row'), function (row, optionIndex) {
                row.querySelector('.option-text').name = 'questions[' + questionIndex + '][options][]';
                var radio = row.querySelector('.option-correct');
                radio.name = 'questions[' + questionIndex + '][correct]';
                radio.value = String(optionIndex);
            });
        });
    }

    function createOptionRow(text, isCorrect) {
        var row = document.createElement('div');
        row.className = 'quiz-option option-row';
        row.innerHTML =
            '<input class="option-correct" type="radio" aria-label="Đáp án đúng">' +
            '<input type="text" class="field__control option-text" placeholder="Nội dung đáp án">' +
            '<button type="button" class="btn btn--quiet remove-option" aria-label="Xóa đáp án">×</button>';

        row.querySelector('.option-text').value = text || '';
        row.querySelector('.option-correct').checked = Boolean(isCorrect);
        row.querySelector('.remove-option').addEventListener('click', function () {
            var card = row.closest('.question-card');
            if (card.querySelectorAll('.option-row').length <= 2) {
                window.alert('Mỗi câu hỏi cần ít nhất 2 đáp án.');
                return;
            }
            row.remove();
            reindex();
        });

        return row;
    }

    function addQuestion(data) {
        data = data || { question: '', options: ['', ''], correct_index: 0 };

        var card = document.createElement('div');
        card.className = 'panel question-card';
        card.innerHTML =
            '<div class="panel__head">' +
            '  <strong class="question-number"></strong>' +
            '  <button type="button" class="btn btn--quiet remove-question">Xóa câu</button>' +
            '</div>' +
            '<input type="text" class="field__control question-text" placeholder="Nội dung câu hỏi">' +
            '<div class="options"></div>' +
            '<button type="button" class="btn btn--outline add-option">Thêm đáp án</button>';

        card.querySelector('.question-text').value = data.question || '';
        var optionsBox = card.querySelector('.options');
        var options = data.options && data.options.length >= 2 ? data.options : ['', ''];
        options.forEach(function (option, index) {
            optionsBox.appendChild(createOptionRow(option, index === data.correct_index));
        });

        card.querySelector('.add-option').addEventListener('click', function () {
            optionsBox.appendChild(createOptionRow('', false));
            reindex();
        });
        card.querySelector('.remove-question').addEventListener('click', function () {
            card.remove();
            reindex();
        });

        container.appendChild(card);
        reindex();
    }

    typeSelect.addEventListener('change', toggleQuizSection);
    addButton.addEventListener('click', function () {
        addQuestion();
    });

    var existing = [];
    var existingScript = document.getElementById('existing-questions');
    if (existingScript) {
        try {
            existing = JSON.parse(existingScript.textContent || '[]');
        } catch (error) {
            existing = [];
        }
    }
    existing.forEach(function (question) {
        addQuestion(question);
    });

    toggleQuizSection();
}());
