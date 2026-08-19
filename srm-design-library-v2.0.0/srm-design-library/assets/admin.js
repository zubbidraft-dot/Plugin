(function($){
    'use strict';
    $(document).on('click','.srmdl-preview-trigger',function(){
        var src=$(this).data('preview');
        $('#srmdl-preview-modal img').attr('src',src);
        $('#srmdl-preview-modal').addClass('is-open').attr('aria-hidden','false');
    });
    $(document).on('click','.srmdl-modal-close,#srmdl-preview-modal',function(e){
        if(e.target!==this && !$(e.target).hasClass('srmdl-modal-close')) return;
        $('#srmdl-preview-modal').removeClass('is-open').attr('aria-hidden','true');
        $('#srmdl-preview-modal img').attr('src','');
    });
    $(document).on('keydown',function(e){if(e.key==='Escape'){$('#srmdl-preview-modal').removeClass('is-open').attr('aria-hidden','true');}});
    $(document).on('click','.srmdl-delete',function(e){var msg=$(this).data('confirm')||'Are you sure?';if(!window.confirm(msg)){e.preventDefault();}});
})(jQuery);
