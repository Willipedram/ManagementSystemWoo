<?php
function build_prompt(array $vars){
    $template = file_get_contents(__DIR__.'/prompt_template.txt');
    return strtr($template,$vars);
}
?>