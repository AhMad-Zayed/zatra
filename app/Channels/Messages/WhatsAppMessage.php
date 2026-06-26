<?php

namespace App\Channels\Messages;

class WhatsAppMessage
{
    protected string $to = '';
    protected string $template = '';
    protected array $params = [];
    protected string $content = '';

    public static function create(): static
    {
        return new static();
    }

    public function to(string $to): static
    {
        $this->to = $to;
        return $this;
    }

    public function template(string $template): static
    {
        $this->template = $template;
        return $this;
    }

    public function params(array $params): static
    {
        $this->params = $params;
        return $this;
    }

    public function content(string $content): static
    {
        $this->content = $content;
        return $this;
    }

    public function toArray(): array
    {
        return [
            'to' => $this->to,
            'template' => $this->template,
            'params' => $this->params,
            'content' => $this->content,
        ];
    }
}
