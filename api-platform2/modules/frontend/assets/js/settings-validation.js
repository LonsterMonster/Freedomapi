document.addEventListener('DOMContentLoaded', function(){

    document
        .querySelectorAll('input')
        .forEach(function(input){

            input.addEventListener(
                'blur',
                function(){

                    input.style.borderColor =
                        input.value
                        ? 'lime'
                        : 'red';
                }
            );
        });
});