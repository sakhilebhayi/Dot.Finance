<?php

namespace App\Exceptions\Bank;

/** A cash-ledger row that is matched to a bank statement line, or reconciled, was edited or deleted. */
class ReconciledTransactionLocked extends ReconciliationRefused {}
