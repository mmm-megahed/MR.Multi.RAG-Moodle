define(['jquery', 'core/notification', 'core/ajax'], function($, notification, ajax) {
    
    var courseId = null;
    
    var init = function(courseid) {
        courseId = courseid;
        bindEvents();
    };
    
    var bindEvents = function() {
        $('#rag-ask-btn').on('click', function() {
            var question = $('#rag-question').val().trim();
            if (question === '') {
                notification.alert('Error', 'Please enter a question.', 'OK');
                return;
            }
            askQuestion(question);
        });
        
        // Allow Enter key to submit (Ctrl+Enter or Shift+Enter for new line)
        $('#rag-question').on('keypress', function(e) {
            if (e.which === 13 && !e.ctrlKey && !e.shiftKey) {
                e.preventDefault();
                $('#rag-ask-btn').click();
            }
        });
    };
    
    var askQuestion = function(question) {
        showLoading(true);
        clearResults();
        
        var requests = ajax.call([{
            methodname: 'block_multimodal_rag_ask_question',
            args: {
                courseid: courseId,
                question: question
            }
        }]);
        
        requests[0].done(function(response) {
            showLoading(false);
            displayAnswer(response);
        }).fail(function(error) {
            showLoading(false);
            notification.exception(error);
            displayError('Failed to get answer. Please try again.');
        });
    };
    
    var showLoading = function(show) {
        if (show) {
            $('#rag-loading').removeClass('d-none');
            $('#rag-ask-btn').prop('disabled', true);
        } else {
            $('#rag-loading').addClass('d-none');
            $('#rag-ask-btn').prop('disabled', false);
        }
    };
    
    var clearResults = function() {
        $('#rag-results').empty();
    };
    
    var displayAnswer = function(response) {
        var resultsContainer = $('#rag-results');
        
        if (response.answer && response.answer.trim() !== '') {
            var answerHtml = '<div class="rag-answer">' +
                '<div class="answer-header"><h5>Answer</h5></div>' +
                '<div class="answer-content">' + formatAnswer(response.answer) + '</div>' +
                '</div>';
            
            if (response.sources && response.sources.length > 0) {
                answerHtml += '<div class="rag-sources mt-3">' +
                    '<div class="sources-header"><h6>Sources</h6></div>' +
                    '<div class="sources-content">' + formatSources(response.sources) + '</div>' +
                    '</div>';
            }
            
            resultsContainer.html(answerHtml);
        } else {
            displayError('No answer found. Please try rephrasing your question.');
        }
    };
    
    var formatAnswer = function(answer) {
        // Basic formatting - convert line breaks to HTML
        return answer.replace(/\n\n/g, '</p><p>').replace(/\n/g, '<br>');
    };
    
    var formatSources = function(sources) {
        var html = '<ul class="sources-list">';
        sources.forEach(function(source, index) {
            html += '<li class="source-item">';
            if (source.filename) {
                html += '<strong>' + source.filename + '</strong>';
            }
            if (source.content) {
                html += '<div class="source-excerpt">' + source.content.substring(0, 200) + '...</div>';
            }
            html += '</li>';
        });
        html += '</ul>';
        return html;
    };
    
    var displayError = function(message) {
        var errorHtml = '<div class="alert alert-warning">' + message + '</div>';
        $('#rag-results').html(errorHtml);
    };
    
    return {
        init: init
    };
});