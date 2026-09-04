(function(){
'use strict';
function ready(fn){if(document.readyState!=='loading'){fn();}else{document.addEventListener('DOMContentLoaded',fn);}}
ready(function(){
  var open=document.getElementById('kornsw-pg-open-discovery');
  var modal=document.getElementById('kornsw-pg-modal');
  var close=document.getElementById('kornsw-pg-modal-close');
  if(open&&modal){open.addEventListener('click',function(e){e.preventDefault();modal.classList.add('is-open');});}
  if(close&&modal){close.addEventListener('click',function(){modal.classList.remove('is-open');});}
  if(modal){modal.addEventListener('click',function(e){if(e.target===modal){modal.classList.remove('is-open');}});}
});
})();
