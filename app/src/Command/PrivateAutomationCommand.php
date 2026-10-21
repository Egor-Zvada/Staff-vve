<?php
declare(strict_types=1);
namespace App\Command;

use App\Service\PrivateAutomationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:private-automation',description:'Run private attendance rules that are due.')]
final class PrivateAutomationCommand extends Command
{
    public function __construct(private readonly PrivateAutomationService $automation) { parent::__construct(); }
    protected function configure(): void { $this->addOption('dry-run',null,InputOption::VALUE_NONE,'Preview with SELECT queries only.')->addOption('status',null,InputOption::VALUE_NONE,'Show scheduler heartbeat and enabled-rule count.'); }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        if ($input->getOption('status')) { $result=$this->automation->status(); }
        else { $result=$this->automation->runDue(null,(bool)$input->getOption('dry-run')); }
        $output->writeln(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
        return empty($result['errors'])?Command::SUCCESS:Command::FAILURE;
    }
}
