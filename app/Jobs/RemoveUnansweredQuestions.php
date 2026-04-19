<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\QuestionGroup;
use App\Models\Question;

class RemoveUnansweredQuestions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    private $groupName;

    public function __construct(
        string $groupName
    )
    {
        $this->groupName = $groupName;
    }

    public function handle()
    {
        $qg = QuestionGroup::where('name','=',$this->groupName)->first();
        if(!$qg) {
            echo "No question group";
            return;
        }
        $c = Question::where('question_group_id', '=', $qg->id)->doesntHave('answer')->doesntHave('edit')->delete();
        echo "Deleted $c questions\n";
    }

    /**
     * Handle a permanently failed job (after $tries is exhausted).
     */
    public function failed(\Throwable $exception)
    {
        \Log::error("RemoveUnansweredQuestions permanently failed", [
            'groupName' => $this->groupName,
            'exception' => $exception->getMessage(),
        ]);
    }

}
