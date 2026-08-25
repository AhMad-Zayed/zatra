<?php
$content = file_get_contents('app/Filament/Resources/BookingResource/Pages/CreateBooking.php');
$method = "
    protected function getRedirectUrl(): string
    {
        return \$this->getResource()::getUrl('view', ['record' => \$this->record]);
    }
";
$content = str_replace("protected function afterCreate(): void", $method . "\n    protected function afterCreate(): void", $content);
file_put_contents('app/Filament/Resources/BookingResource/Pages/CreateBooking.php', $content);
echo "Added getRedirectUrl\n";
