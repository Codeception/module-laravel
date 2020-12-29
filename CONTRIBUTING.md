# How to Contribute

First of all: Contributions are very welcome!

**Does your change require a test?**

## No, my change does not require a test
So you're going to make a small change or improve the documentation? Hey, you rock!

- Either just edit [`Laravel.php`](https://github.com/Codeception/module-laravel/blob/main/src/Codeception/Module/Laravel.php) on GitHub's website.
- Make sure to add a descriptive title and add an explanation for your changes.

> :bulb: You can also create a *fork* and *cloning it* on your local machine, as explained in the next section.

## Yes, my change requires a test

So you're going to add or modify functionality? Hey, you rock too!

You can use our prepared [Codeception/laravel-module-tests](https://github.com/Codeception/laravel-module-tests).
It is a minimal (but complete) Laravel project, ready to run tests.

### 1. Edit locally

- Go to [Codeception/laravel-module-tests](https://github.com/Codeception/laravel-module-tests) and fork the project.
  Then follow the installation instructions.
  <br/>
- Edit the module's source code in the `vendor/codeception/module-laravel/src/Codeception/Module/Laravel.php` file.
  <br/>
- If you created a new method, you can test it by adding a test in the `tests/Functional/LaravelModuleCest.php` file.
> :bulb: Be sure to Rebuild Codeception's "Actor" classes (see [Console Commands](https://codeception.com/docs/reference/Commands#Build)):
> ```shell
> vendor/bin/codecept clean
> vendor/bin/codecept build
> ```
> With this, your IDE will be able to recognize and autocomplete your new method.

- Then, run the tests with the `vendor/bin/codecept run Functional` command.

### 2. Confirm your changes

- If you are satisfied with your changes, the next step is to fork [Codeception/module-laravel](https://github.com/Codeception/module-laravel).
  In your terminal, go to another directory, then:
   ```shell
   # Clone the repo
   git clone https://github.com/YourUserName/module-laravel.git

   # Create a new branch for your change
   cd module-laravel
   git checkout -b new_feature
   ```
> :bulb: If you've created a fork before, make sure to [sync the changes](https://stackoverflow.com/a/7244456).

- Copy the changes from the `Laravel.php` of the test project to the `src/Codeception/Module/Laravel.php` file on your Module's fork.
  <br/>
- Commit:
   ```shell
   git add --all
   git commit --message="Briefly explain what your change is about"
   git push --set-upstream origin new_feature
   ```

### 3. Create a Pull Request

- In the CLI output, click on the link to `https://github.com/YourUserName/module-laravel/pull/new/new_feature` to create a Pull Request through GitHub.com.

Now wait for feedback on your Pull Request. If all is fine and gets merged...

### 4. Send a Test

- In the test project (`laravel-module-tests`), create a test with the same name as your new method in `tests/Functional/LaravelModuleCest.php`, following alphabetical order.

- Run the tests with `vendor/bin/codecept run Functional` command.

- Commit:
    ```shell
    git checkout -b new_test
    git add --all
    git commit --message="Describe what feature you are testing"
    git push --set-upstream origin new_test
    ```

- In the CLI output, click on the link to `https://github.com/YourUserName/laravel-module-tests/pull/new/new_test` to create a Pull Request through `GitHub.com`.
  Don't forget to add a link to the module's Pull Request you created.
