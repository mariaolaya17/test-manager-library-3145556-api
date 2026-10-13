<?php

namespace App\Application\Services;

use App\Application\Ports\BookRepository;
use App\Application\Ports\LoanRepository;
use App\Application\Ports\MemberRepository;
use App\Domain\Exceptions\BookNotAvailable;
use App\Domain\Exceptions\DuplicateBookInLoan;
use App\Domain\Exceptions\MemberHasOverdue;
use App\Domain\Exceptions\MemberLimitExceeded;
use App\Domain\Loan;
use App\Domain\LoanItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LendBooksService
{
    public function __construct(
        private readonly LoanRepository $loanRepository,
        private readonly BookRepository $bookRepository,
        private readonly MemberRepository $memberRepository,
    ) {
    }

    public function lend(string $memberId, array $bookIds, string $userId, \DateTimeImmutable $now): Loan
    {
        if (count($bookIds) < 1 || count($bookIds) > 3) {
            throw new \App\Domain\Exceptions\ValidationError('A loan must have 1 to 3 items.');
        }

        $uniqueBookIds = array_unique($bookIds);
        if (count($uniqueBookIds) !== count($bookIds)) {
            throw new DuplicateBookInLoan();
        }

        if ($this->loanRepository->hasOverdueLoansForMember($memberId)) {
            throw new MemberHasOverdue($memberId);
        }

        $activeLoans = $this->loanRepository->countActiveLoansForMember($memberId);
        $requestedBooks = count($uniqueBookIds);

        if ($activeLoans + $requestedBooks > 3) {
            throw new MemberLimitExceeded($activeLoans);
        }

        $books = [];
        foreach ($uniqueBookIds as $bookId) {
            $book = $this->bookRepository->findById($bookId);
            if ($book === null) {
                throw new \App\Domain\Exceptions\ValidationError("Book not found: {$bookId}");
            }
            $books[] = $book;
        }

        $loanId = Str::uuid()->toString();
        $loan = new Loan(
            id: $loanId,
            memberId: $memberId,
            loanedByUserId: $userId,
            loanedAt: $now,
        );

        $items = [];
        foreach ($books as $book) {
            $itemId = Str::uuid()->toString();
            $dueAt = $now->modify('+14 days');

            $item = new LoanItem(
                id: $itemId,
                loanId: $loanId,
                bookId: $book->id,
                bookTitle: $book->title,
                isbn: $book->isbn->value,
                categoryName: $book->categoryName,
                dueAt: $dueAt,
            );

            $items[] = $item;
        }

        DB::transaction(function () use ($books, $items, $loan) {
            foreach ($books as $book) {
                $affected = DB::table('library.book')
                    ->where('id', $book->id)
                    ->where('available_copies', '>', 0)
                    ->whereNull('deleted_at')
                    ->update(['available_copies' => DB::raw('available_copies - 1')]);

                if ($affected === 0) {
                    throw new BookNotAvailable($book->title);
                }
            }

            $this->loanRepository->save($loan);
        });

        foreach ($items as $item) {
            $loan->items[] = $item;
        }

        return $loan;
    }
}
