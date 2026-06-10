<?php /** @var string $name */ ?>
Hello <?= /** @phpstan-ignore variable.undefined ($this is bound at runtime by Renderer::render() non-static closure) */ $this->escape($name) ?>!
